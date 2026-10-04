<?php
declare(strict_types=1);

namespace Lettermint\Email\Service;

use Laminas\Mime\Message as LaminasMimeMessage;
use Lettermint\Attachment;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Mail\MimePartInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\AbstractMultipartPart;
use Symfony\Component\Mime\Part\AbstractPart;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\TextPart;

/**
 * Reads the HTML and text bodies and the attachments of a Magento email.
 *
 * Magento 2.4.7 and older build the body as a Laminas MIME message: a flat
 * list of Magento MimePart (or Laminas Part) objects, where a nested
 * multipart is a part with raw content.
 * Magento 2.4.8 and newer build a Symfony MIME part tree, for example
 * multipart/mixed with a multipart/alternative body and attachments.
 */
class EmailContentExtractor
{
    /** Content-IDs the Lettermint API accepts. */
    private const CONTENT_ID = '/\A[A-Za-z0-9._@-]+\z/';

    /** Extensions for attachments without a file name. */
    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'application/xml' => 'xml',
        'application/json' => 'json',
        'image/gif' => 'gif',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/svg+xml' => 'svg',
        'image/webp' => 'webp',
        'text/calendar' => 'ics',
        'text/csv' => 'csv',
        'text/html' => 'html',
        'text/plain' => 'txt',
        'text/xml' => 'xml',
    ];

    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    /**
     * @return array{html: string|null, text: string|null, attachments: list<Attachment>}
     * @throws MailException When the body has no HTML or text content, or cannot be read.
     */
    public function extractContent(EmailMessageInterface $message): array
    {
        $content = ['html' => null, 'text' => null, 'attachments' => []];

        $body = $this->body($message);
        if ($body instanceof LaminasMimeMessage) {
            foreach ($body->getParts() as $part) {
                $this->addLaminasPart($part, $content);
            }
        } elseif ($body instanceof AbstractPart) {
            $this->addSymfonyPart($body, $content);
        } elseif (is_string($body) || $body instanceof \Stringable) {
            $this->addBody('text', (string)$body, $content);
        } else {
            throw new MailException(__('Unsupported email body type %1.', get_debug_type($body)));
        }

        if ($content['html'] === null && $content['text'] === null) {
            throw new MailException(__('No email body content found'));
        }

        return $content;
    }

    /**
     * The body type depends on the Magento version (a Laminas MIME message up
     * to 2.4.7, a Symfony MIME part from 2.4.8), whatever the interface says.
     */
    private function body(EmailMessageInterface $message): mixed
    {
        return $message->getBody();
    }

    /**
     * Reads a part from its MIME headers. Magento's MimePart getters (such as
     * getFileName() and getBoundary()) throw a TypeError when the value is
     * not set, so they are not used.
     *
     * @param object $part A Magento MimePart, or a Laminas Part added by a module or parsed from a nested multipart.
     * @param array{html: string|null, text: string|null, attachments: list<Attachment>} $content
     * @param bool $nested Whether the part was parsed from a nested multipart.
     */
    private function addLaminasPart(object $part, array &$content, bool $nested = false): void
    {
        if (!$part instanceof MimePartInterface && !$part instanceof \Laminas\Mime\Part) {
            throw new MailException(__('Unsupported email part type %1.', get_debug_type($part)));
        }

        $headers = [];
        foreach ($part->getHeadersArray() as $header) {
            if (is_array($header) && isset($header[0], $header[1])) {
                $headers[strtolower((string)$header[0])] = (string)$header[1];
            }
        }
        $contentType = $headers['content-type'] ?? '';
        [$type, $parameters] = $this->parseHeaderValue($contentType);

        if (str_starts_with($type, 'multipart/')) {
            $boundary = $parameters['boundary'] ?? null;
            if (!$boundary) {
                throw new MailException(__('A multipart email part has no boundary.'));
            }
            $nested = LaminasMimeMessage::createFromMessage((string)$part->getRawContent(), $boundary);
            foreach ($nested->getParts() as $nestedPart) {
                $this->addLaminasPart($nestedPart, $content, true);
            }

            return;
        }

        [$disposition, $dispositionParameters] = $this->parseHeaderValue($headers['content-disposition'] ?? '');
        $filename = $dispositionParameters['filename'] ?? $parameters['name'] ?? null;
        if ($filename === null && $part instanceof \Laminas\Mime\Part) {
            $filename = $part->getFileName() ?: null;
        }
        $raw = (string)$part->getRawContent();
        if ($nested) {
            // The line break before a boundary belongs to the boundary (RFC 2046).
            $raw = (string)preg_replace('/\r?\n\z/', '', $raw);
        }

        if (($type === 'text/html' || $type === 'text/plain') && $disposition !== 'attachment' && !$filename) {
            $this->addBody($type === 'text/html' ? 'html' : 'text', $raw, $content);

            return;
        }

        $content['attachments'][] = $this->attachment(
            $raw,
            $filename,
            $contentType,
            $headers['content-id'] ?? null,
            count($content['attachments']) + 1
        );
    }

    /**
     * @param array{html: string|null, text: string|null, attachments: list<Attachment>} $content
     */
    private function addSymfonyPart(AbstractPart $part, array &$content): void
    {
        if ($part instanceof AbstractMultipartPart) {
            foreach ($part->getParts() as $nestedPart) {
                $this->addSymfonyPart($nestedPart, $content);
            }

            return;
        }

        // DataPart extends TextPart, so check it first.
        if ($part instanceof DataPart) {
            $content['attachments'][] = $this->attachment(
                $part->getBody(),
                $part->getFilename(),
                $part->getContentType(),
                $part->hasContentId() ? $part->getContentId() : null,
                count($content['attachments']) + 1
            );

            return;
        }

        if ($part instanceof TextPart) {
            $subtype = strtolower($part->getMediaSubtype());
            if (($subtype === 'html' || $subtype === 'plain')
                && $part->getDisposition() !== 'attachment'
                && $part->getName() === null
            ) {
                $this->addBody($subtype === 'html' ? 'html' : 'text', $part->getBody(), $content);

                return;
            }

            $content['attachments'][] = $this->attachment(
                $part->getBody(),
                $part->getName(),
                'text/' . $subtype,
                null,
                count($content['attachments']) + 1
            );

            return;
        }

        throw new MailException(__('Unsupported email part type %1.', get_debug_type($part)));
    }

    /**
     * The first HTML and the first text part are the bodies.
     *
     * @param 'html'|'text' $key
     * @param array{html: string|null, text: string|null, attachments: list<Attachment>} $content
     */
    private function addBody(string $key, string $value, array &$content): void
    {
        if ($value !== '' && $content[$key] === null) {
            $content[$key] = $value;
        }
    }

    private function attachment(
        string $bytes,
        ?string $filename,
        ?string $contentType,
        ?string $contentId,
        int $number
    ): Attachment {
        [$type] = $this->parseHeaderValue((string)$contentType);
        $contentType = trim((string)$contentType);
        if ($contentType === '' || strlen($contentType) > 255 || preg_match('/[\r\n]/', $contentType)) {
            $contentType = $type !== '' ? $type : null;
        }

        $filename = trim(str_replace(["\r", "\n", "\0"], '', (string)$filename));
        if ($filename === '') {
            $extension = self::EXTENSIONS[$type] ?? null;
            $filename = 'attachment-' . $number . ($extension !== null ? '.' . $extension : '');
        }

        $contentId = $contentId !== null ? trim($contentId, " \t<>") : null;
        if ($contentId === '') {
            $contentId = null;
        }
        if ($contentId !== null && preg_match(self::CONTENT_ID, $contentId) !== 1) {
            $this->logger->warning('Lettermint: sending an inline attachment without its Content-ID, which has characters the Lettermint API does not accept', [
                'filename' => $filename,
            ]);
            $contentId = null;
        }

        return new Attachment($filename, $bytes, $contentType, $contentId);
    }

    /**
     * Splits a MIME header value such as `attachment; filename="a.pdf"` into
     * the lowercase value and its parameters.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function parseHeaderValue(string $value): array
    {
        $segments = explode(';', $value);
        $main = strtolower(trim((string)array_shift($segments)));
        $parameters = [];
        foreach ($segments as $segment) {
            if (!str_contains($segment, '=')) {
                continue;
            }
            [$name, $parameterValue] = explode('=', $segment, 2);
            $parameters[strtolower(trim($name))] = trim(trim($parameterValue), '"');
        }

        return [$main, $parameters];
    }
}
