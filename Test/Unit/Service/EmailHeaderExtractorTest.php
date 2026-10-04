<?php
declare(strict_types=1);

namespace Lettermint\Email\Test\Unit\Service;

use Lettermint\Email\Service\EmailHeaderExtractor;
use Lettermint\Email\Test\Unit\MagentoMessages;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Mail\MimePart;
use PHPUnit\Framework\TestCase;

final class EmailHeaderExtractorTest extends TestCase
{
    use MagentoMessages;

    public function testLaminasHeadersKeepOnlyCustomHeaders(): void
    {
        // Laminas Headers::toArray(): name => value, or a list for repeated headers.
        $message = $this->createMock(EmailMessageInterface::class);
        $message->method('getHeaders')->willReturn([
            'Date' => 'Sun, 04 Oct 2026 12:00:00 +0000',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/html; charset=utf-8',
            'From' => 'Example Shop <shop@example.com>',
            'To' => 'jane@example.com',
            'Subject' => 'Your order',
            'Message-ID' => '<abc@example.com>',
            'X-Order-Id' => '000000123',
            'X-Mailer' => ['Magento', 'Second value'],
            'List-Unsubscribe' => '<https://shop.example.com/unsubscribe>',
            'X-Lettermint-Pool' => 'internal',
            'X-LM-Preserve-Message-ID' => 'true',
            'X-Auth-Token' => 'secret',
            'Authorization' => 'Bearer secret',
            'DKIM-Signature' => 'v=1; a=rsa-sha256',
        ]);

        $this->assertSame([
            'X-Order-Id' => '000000123',
            'X-Mailer' => 'Magento',
            'List-Unsubscribe' => '<https://shop.example.com/unsubscribe>',
        ], (new EmailHeaderExtractor())->extractHeaders($message));
    }

    public function testSymfonyHeadersKeepOnlyCustomHeaders(): void
    {
        $this->requireSymfonyMime();
        $message = $this->magentoMessage([new MimePart('<p>Hi</p>')]);
        $headers = $message->getSymfonyMessage()->getHeaders();
        $headers->addTextHeader('X-Order-Id', '000000123');
        $headers->addTextHeader('X-Customer-Name', 'Zoë Müller');
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        $headers->addTextHeader('X-Api-Key', 'secret');
        $headers->addIdHeader('Message-ID', 'abc@example.com');

        $this->assertSame([
            'X-Order-Id' => '000000123',
            'X-Customer-Name' => 'Zoë Müller',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ], (new EmailHeaderExtractor())->extractHeaders($message));
    }

    public function testMagentoMessageWithoutCustomHeaders(): void
    {
        $message = $this->magentoMessage([new MimePart('<p>Hi</p>')]);

        $this->assertSame([], (new EmailHeaderExtractor())->extractHeaders($message));
    }
}
