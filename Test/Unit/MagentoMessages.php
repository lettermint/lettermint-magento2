<?php
declare(strict_types=1);

namespace Lettermint\Email\Test\Unit;

use Magento\Framework\Mail\Address;
use Magento\Framework\Mail\AddressFactory;
use Magento\Framework\Mail\EmailMessage;
use Magento\Framework\Mail\MimeMessage;
use Magento\Framework\Mail\MimeMessageInterfaceFactory;
use Magento\Framework\Mail\MimePart;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Builds real Magento email messages, as Magento and third-party modules
 * create them on the installed Magento version.
 *
 * @mixin TestCase
 */
trait MagentoMessages
{
    /** Magento 2.4.7 and older: Laminas MIME. */
    private static function usesLaminasMime(): bool
    {
        return !method_exists(EmailMessage::class, 'getSymfonyMessage');
    }

    private function requireLaminasMime(): void
    {
        if (!self::usesLaminasMime()) {
            $this->markTestSkipped('This Magento version (2.4.8+) uses Symfony MIME.');
        }
    }

    private function requireSymfonyMime(): void
    {
        if (self::usesLaminasMime()) {
            $this->markTestSkipped('This Magento version (2.4.7 or older) uses Laminas MIME.');
        }
    }

    /**
     * A message whose body has the given Magento MIME parts. On Magento 2.4.8+
     * Magento keeps only the first text part; set the body with symfonyBody().
     *
     * @param list<object> $parts
     */
    private function magentoMessage(array $parts, string $subject = 'Your order'): EmailMessage
    {
        return new EmailMessage(
            new MimeMessage($parts),
            [new Address('jane@example.com', 'Jane')],
            new MimeMessageInterfaceFactory(),
            new AddressFactory(),
            [new Address('shop@example.com', 'Example Shop')],
            null,
            null,
            null,
            null,
            $subject,
            'utf-8',
            new NullLogger()
        );
    }

    /**
     * A message with a Symfony MIME body (Magento 2.4.8+), as modules that add
     * attachments build it.
     */
    private function symfonyMessage(\Symfony\Component\Mime\Part\AbstractPart $body): EmailMessage
    {
        $message = $this->magentoMessage([new MimePart('<p>placeholder</p>')]);
        $message->getSymfonyMessage()->setBody($body);

        return $message;
    }
}
