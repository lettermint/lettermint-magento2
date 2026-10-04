<?php
declare(strict_types=1);

namespace Lettermint\Email\Model;

use Lettermint\Email\Service\EmailContentExtractor;
use Lettermint\Exceptions\ApiException;
use Lettermint\Exceptions\RateLimitException;
use Lettermint\Exceptions\TimeoutException;
use Lettermint\Exceptions\ValidationException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\Address;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Mail\TransportInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class Transport implements TransportInterface
{
    private const CONFIG_PATH_ENABLED = 'lettermint_email/general/enabled';
    private const CONFIG_PATH_API_TOKEN = 'lettermint_email/general/api_token';
    private const CONFIG_PATH_TRANSACTIONAL_ROUTE = 'lettermint_email/routes/transactional_route';
    private const CONFIG_PATH_NEWSLETTER_ROUTE = 'lettermint_email/routes/newsletter_route';

    private ?EmailMessageInterface $message = null;

    public function __construct(
        private ScopeConfigInterface    $scopeConfig,
        private LoggerInterface         $logger,
        private EncryptorInterface      $encryptor,
        private EmailContentExtractor   $contentExtractor,
        private LettermintClientFactory $clientFactory
    )
    {
    }

    public function getMessage(): EmailMessageInterface
    {
        if (!$this->message) {
            throw new MailException(__('No message set for Lettermint transport'));
        }
        return $this->message;
    }

    public function setMessage(EmailMessageInterface $message): void
    {
        $this->message = $message;
    }

    public function sendMessage(): void
    {
        $message = $this->getMessage();

        if (!$this->isEnabled()) {
            $this->logger->warning('Lettermint email transport is not enabled');
            throw new MailException(__('Lettermint email transport is not enabled.'));
        }

        $apiToken = $this->getApiToken();
        if (!$apiToken) {
            $this->logger->warning('Lettermint API token is not configured');
            throw new MailException(__('Lettermint API token is not configured.'));
        }

        try {
            // Determine which route to use based on email type. Resolved here,
            // as the newsletter detection inspects the caller backtrace.
            $route = $this->isNewsletterEmail() ? $this->getNewsletterRoute() : $this->getTransactionalRoute();

            // Build a new payload for every email. The SDK client keeps no
            // message state, so nothing carries over to the next email.
            $payload = $this->buildPayload($message, $route);

            $response = $this->clientFactory->create($apiToken)->emails->send($payload);

            $this->logger->debug('Lettermint accepted the email', [
                'message_id' => $response->message_id,
                'status' => $response->status,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send email via Lettermint: ' . $e->getMessage(), $this->errorContext($e));
            throw new MailException(__('Failed to send email: %1', $e->getMessage()), $e);
        }
    }

    /**
     * Maps the Magento message to a Lettermint send request in the API's format.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(EmailMessageInterface $message, ?string $route): array
    {
        $payload = [];

        // Magento manages the sender configuration; keep the sender name.
        $from = $message->getFrom();
        if ($from) {
            $payload['from'] = $this->formatSender(reset($from));
        }

        $to = $this->emailAddresses($message->getTo());
        if ($to) {
            $payload['to'] = $to;
        }

        $cc = $this->emailAddresses($message->getCc());
        if ($cc) {
            $payload['cc'] = $cc;
        }

        $bcc = $this->emailAddresses($message->getBcc());
        if ($bcc) {
            $payload['bcc'] = $bcc;
        }

        $replyTo = $this->emailAddresses($message->getReplyTo());
        if ($replyTo) {
            $payload['reply_to'] = $replyTo;
        }

        $subject = $message->getSubject();
        if ($subject) {
            $payload['subject'] = $subject;
        }

        // Extract email content using shared service, without tampering
        $content = $this->contentExtractor->extractContent($message);
        if ($content['html']) {
            $payload['html'] = $content['html'];
        }
        if ($content['text']) {
            $payload['text'] = $content['text'];
        }

        if ($route) {
            $payload['route'] = $route;
        }

        return $payload;
    }

    private function formatSender(mixed $address): string
    {
        if ($address instanceof Address) {
            return $address->getName()
                ? $address->getName() . ' <' . $address->getEmail() . '>'
                : (string)$address->getEmail();
        }

        return (string)$address;
    }

    /**
     * @param array<mixed>|null $addresses
     * @return list<string>
     */
    private function emailAddresses(?array $addresses): array
    {
        $emails = [];
        foreach ($addresses ?? [] as $address) {
            $emails[] = $address instanceof Address ? (string)$address->getEmail() : (string)$address;
        }

        return $emails;
    }

    /**
     * Log context for a failed send. SDK exceptions never contain the API token.
     *
     * @return array<string, mixed>
     */
    private function errorContext(\Exception $e): array
    {
        $context = ['exception' => $e];

        if ($e instanceof ApiException) {
            $context['status'] = $e->status;
            $context['error_code'] = $e->errorCode;
        }
        if ($e instanceof ValidationException) {
            $context['errors'] = $e->errors;
        }
        if ($e instanceof RateLimitException) {
            $context['retry_after'] = $e->retryAfter;
        }
        if ($e instanceof TimeoutException) {
            $context['timeout'] = $e->timeout;
        }

        return $context;
    }

    private function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    private function getApiToken(): ?string
    {
        $encryptedToken = $this->scopeConfig->getValue(
            self::CONFIG_PATH_API_TOKEN,
            ScopeInterface::SCOPE_STORE
        );

        if (!$encryptedToken) {
            return null;
        }

        try {
            return trim($this->encryptor->decrypt($encryptedToken)) ?: null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to decrypt Lettermint API token: ' . $e->getMessage());
            return null;
        }
    }


    private function getTransactionalRoute(): ?string
    {
        return $this->scopeConfig->getValue(
            self::CONFIG_PATH_TRANSACTIONAL_ROUTE,
            ScopeInterface::SCOPE_STORE
        );
    }

    private function getNewsletterRoute(): ?string
    {
        return $this->scopeConfig->getValue(
            self::CONFIG_PATH_NEWSLETTER_ROUTE,
            ScopeInterface::SCOPE_STORE
        );
    }

    private function isNewsletterEmail(): bool
    {
        if (!$this->message) {
            return false;
        }

        // For now, let's detect newsletters by checking the debug_backtrace for Newsletter module
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);
        foreach ($backtrace as $trace) {
            if (isset($trace['class']) && strpos($trace['class'], 'Newsletter') !== false) {
                $this->logger->info('Lettermint Transport: Newsletter detected via backtrace', [
                    'class' => $trace['class']
                ]);
                return true;
            }
        }

        // Fallback: Not detected as newsletter via backtrace
        return false;
    }

}
