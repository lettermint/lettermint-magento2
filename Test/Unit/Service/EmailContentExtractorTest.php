<?php
declare(strict_types=1);

namespace Lettermint\Email\Test\Unit\Service;

use Lettermint\Attachment;
use Lettermint\Email\Service\EmailContentExtractor;
use Lettermint\Email\Test\Unit\ArrayLogger;
use Lettermint\Email\Test\Unit\MagentoMessages;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Mail\MimeInterface;
use Magento\Framework\Mail\MimePart;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\Multipart\RelatedPart;
use Symfony\Component\Mime\Part\TextPart;

final class EmailContentExtractorTest extends TestCase
{
    use MagentoMessages;

    private const PDF = "%PDF-1.4\n\x00\x01binary invoice\xff";
    private const PNG = "\x89PNG\r\n\x1a\nlogo";

    private ArrayLogger $logger;
    private EmailContentExtractor $extractor;

    protected function setUp(): void
    {
        $this->logger = new ArrayLogger();
        $this->extractor = new EmailContentExtractor($this->logger);
    }

    // Magento 2.4.6 and 2.4.7 (Laminas MIME)

    public function testLaminasHtmlEmail(): void
    {
        $this->requireLaminasMime();

        $content = $this->extract($this->magentoMessage([new MimePart('<p>Thanks for your order</p>')]));

        $this->assertSame('<p>Thanks for your order</p>', $content['html']);
        $this->assertNull($content['text']);
        $this->assertSame([], $content['attachments']);
    }

    public function testLaminasEmailWithAttachments(): void
    {
        $this->requireLaminasMime();
        $logo = new \Laminas\Mime\Part(self::PNG);
        $logo->setType('image/png');
        $logo->setId('logo@example.com');
        $logo->setDisposition('inline');
        $logo->setEncoding('base64');

        $content = $this->extract($this->magentoMessage([
            new MimePart('<p>Invoice <img src="cid:logo@example.com"></p>'),
            new MimePart(
                self::PDF,
                'application/pdf',
                'invoice-0001.pdf',
                MimeInterface::DISPOSITION_ATTACHMENT,
                MimeInterface::ENCODING_BASE64
            ),
            $logo,
        ]));

        $this->assertSame('<p>Invoice <img src="cid:logo@example.com"></p>', $content['html']);
        $this->assertSame([
            [
                'filename' => 'invoice-0001.pdf',
                'content' => base64_encode(self::PDF),
                'content_type' => 'application/pdf; charset=utf-8',
            ],
            [
                'filename' => 'attachment-2.png',
                'content' => base64_encode(self::PNG),
                'content_type' => 'image/png',
                'content_id' => 'logo@example.com',
            ],
        ], $this->arrays($content['attachments']));
    }

    public function testLaminasMultipartAlternativeWithAttachment(): void
    {
        $this->requireLaminasMime();
        $text = new \Laminas\Mime\Part('Thanks for your order');
        $text->setType('text/plain');
        $text->setCharset('utf-8');
        $text->setEncoding('quoted-printable');
        $html = new \Laminas\Mime\Part('<p>Thanks for your order – €10</p>');
        $html->setType('text/html');
        $html->setCharset('utf-8');
        $html->setEncoding('quoted-printable');
        $alternative = new \Laminas\Mime\Message();
        $alternative->setParts([$text, $html]);
        $boundary = $alternative->getMime()->boundary();
        $alternativePart = new \Laminas\Mime\Part($alternative->generateMessage());
        $alternativePart->setType('multipart/alternative');
        $alternativePart->setBoundary($boundary);

        $content = $this->extract($this->magentoMessage([
            $alternativePart,
            new MimePart(
                self::PDF,
                'application/pdf',
                'invoice.pdf',
                MimeInterface::DISPOSITION_ATTACHMENT,
                MimeInterface::ENCODING_BASE64
            ),
        ]));

        $this->assertSame('Thanks for your order', $content['text']);
        $this->assertSame('<p>Thanks for your order – €10</p>', $content['html']);
        $this->assertCount(1, $content['attachments']);
        $this->assertSame('invoice.pdf', $content['attachments'][0]->filename);
        $this->assertSame(base64_encode(self::PDF), $content['attachments'][0]->toArray()['content']);
    }

    // Magento 2.4.8 and newer (Symfony MIME)

    public function testSymfonyHtmlEmail(): void
    {
        $this->requireSymfonyMime();

        $content = $this->extract($this->magentoMessage([new MimePart('<p>Thanks for your order</p>')]));

        $this->assertSame('<p>Thanks for your order</p>', $content['html']);
        $this->assertNull($content['text']);
        $this->assertSame([], $content['attachments']);
    }

    public function testSymfonyMultipartAlternativeWithAttachment(): void
    {
        $this->requireSymfonyMime();

        $content = $this->extract($this->symfonyMessage(new MixedPart(
            new AlternativePart(
                new TextPart('Thanks for your order'),
                new TextPart('<p>Thanks for your order</p>', 'utf-8', 'html')
            ),
            new DataPart(self::PDF, 'invoice.pdf', 'application/pdf')
        )));

        $this->assertSame('Thanks for your order', $content['text']);
        $this->assertSame('<p>Thanks for your order</p>', $content['html']);
        $this->assertSame([
            ['filename' => 'invoice.pdf', 'content' => base64_encode(self::PDF), 'content_type' => 'application/pdf'],
        ], $this->arrays($content['attachments']));
    }

    public function testSymfonyInlineImageAndAttachment(): void
    {
        $this->requireSymfonyMime();
        $logo = (new DataPart(self::PNG, 'logo.png', 'image/png'))->asInline()->setContentId('logo@example.com');

        $content = $this->extract($this->symfonyMessage(new MixedPart(
            new RelatedPart(new TextPart('<img src="cid:logo@example.com">', 'utf-8', 'html'), $logo),
            new DataPart(self::PDF, null, 'application/pdf'),
            (new TextPart("Name,Total\nJane,10", 'utf-8', 'csv'))->setDisposition('attachment')->setName('orders.csv')
        )));

        $this->assertSame('<img src="cid:logo@example.com">', $content['html']);
        $this->assertNull($content['text']);
        $this->assertSame([
            [
                'filename' => 'logo.png',
                'content' => base64_encode(self::PNG),
                'content_type' => 'image/png',
                'content_id' => 'logo@example.com',
            ],
            ['filename' => 'attachment-2.pdf', 'content' => base64_encode(self::PDF), 'content_type' => 'application/pdf'],
            ['filename' => 'orders.csv', 'content' => base64_encode("Name,Total\nJane,10"), 'content_type' => 'text/csv'],
        ], $this->arrays($content['attachments']));
    }

    public function testContentIdTheApiRejectsIsDroppedWithWarning(): void
    {
        $this->requireSymfonyMime();
        $logo = (new DataPart(self::PNG, 'logo.png', 'image/png'))->asInline()->setContentId('logo image@example.com');

        $content = $this->extract($this->symfonyMessage(new RelatedPart(new TextPart('<p>Hi</p>', 'utf-8', 'html'), $logo)));

        $this->assertArrayNotHasKey('content_id', $content['attachments'][0]->toArray());
        $this->assertSame('warning', $this->logger->records[0]['level']);
    }

    // Any Magento version

    public function testUnsupportedBodyIsAMailException(): void
    {
        $message = $this->createMock(EmailMessageInterface::class);
        $message->method('getBody')->willReturn(new \ArrayObject());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Unsupported email body type ArrayObject.');
        $this->extractor->extractContent($message);
    }

    public function testEmptyBodyIsAMailException(): void
    {
        $message = $this->createMock(EmailMessageInterface::class);
        $message->method('getBody')->willReturn('');

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('No email body content found');
        $this->extractor->extractContent($message);
    }

    /**
     * @return array{html: string|null, text: string|null, attachments: list<Attachment>}
     */
    private function extract(EmailMessageInterface $message): array
    {
        return $this->extractor->extractContent($message);
    }

    /**
     * @param list<Attachment> $attachments
     * @return list<array<string, string>>
     */
    private function arrays(array $attachments): array
    {
        return array_map(static fn (Attachment $attachment): array => $attachment->toArray(), $attachments);
    }
}
