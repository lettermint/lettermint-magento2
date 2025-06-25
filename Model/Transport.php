<?php
declare(strict_types=1);

namespace Lettermint\Email\Model;

use Lettermint\Email\Service\EmailContentExtractor;
use Lettermint\Lettermint;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\MailException;
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
        private ScopeConfigInterface  $scopeConfig,
        private LoggerInterface       $logger,
        private EncryptorInterface    $encryptor,
        private EmailContentExtractor $contentExtractor
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
            $lettermint = new Lettermint($apiToken);
            $email = $lettermint->email;

            // Handle from address - Magento manages sender configuration
            $from = $this->message->getFrom();
            if ($from) {
                // Handle Magento\Framework\Mail\Address object
                if ($from instanceof \Magento\Framework\Mail\Address) {
                    $fromString = $from->getName() ? $from->getName() . ' <' . $from->getEmail() . '>' : $from->getEmail();
                } elseif (is_array($from)) {
                    $firstFrom = reset($from);
                    $fromString = $firstFrom instanceof \Magento\Framework\Mail\Address
                        ? ($firstFrom->getName() ? $firstFrom->getName() . ' <' . $firstFrom->getEmail() . '>' : $firstFrom->getEmail())
                        : $firstFrom;
                } else {
                    $fromString = (string)$from;
                }
                $email->from($fromString);
            }

            $to = $this->message->getTo();
            if ($to) {
                $toEmails = [];
                foreach ($to as $address) {
                    if ($address instanceof \Magento\Framework\Mail\Address) {
                        $toEmails[] = $address->getEmail();
                    } else {
                        $toEmails[] = (string)$address;
                    }
                }
                $email->to(...$toEmails);
            }

            $cc = $this->message->getCc();
            if ($cc) {
                $ccEmails = [];
                foreach ($cc as $address) {
                    if ($address instanceof \Magento\Framework\Mail\Address) {
                        $ccEmails[] = $address->getEmail();
                    } else {
                        $ccEmails[] = (string)$address;
                    }
                }
                $email->cc(...$ccEmails);
            }

            $bcc = $this->message->getBcc();
            if ($bcc) {
                $bccEmails = [];
                foreach ($bcc as $address) {
                    if ($address instanceof \Magento\Framework\Mail\Address) {
                        $bccEmails[] = $address->getEmail();
                    } else {
                        $bccEmails[] = (string)$address;
                    }
                }
                $email->bcc(...$bccEmails);
            }

            $replyTo = $this->message->getReplyTo();
            if ($replyTo) {
                if ($replyTo instanceof \Magento\Framework\Mail\Address) {
                    $email->replyTo($replyTo->getEmail());
                } else {
                    $email->replyTo((string)$replyTo);
                }
            }

            $subject = $this->message->getSubject();
            if ($subject) {
                $email->subject($subject);
            }

            // Extract email content using shared service
            $content = $this->contentExtractor->extractContent($this->message);

            // Set content without tampering
            if ($content['html']) {
                $email->html($content['html']);
            }
            if ($content['text']) {
                $email->text($content['text']);
            }

            // Determine which route to use based on email type
            $route = $this->isNewsletterEmail() ? $this->getNewsletterRoute() : $this->getTransactionalRoute();
            if ($route) {
                $email->route($route);
            }

            $response = $email->send();

            if (!$response) {
                throw new MailException(__('Failed to send email via Lettermint.'));
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to send email via Lettermint: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw new MailException(__('Failed to send email: %1', $e->getMessage()), $e);
        }
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
            return $this->encryptor->decrypt($encryptedToken);
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
