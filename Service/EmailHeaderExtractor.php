<?php
declare(strict_types=1);

namespace Lettermint\Email\Service;

use Magento\Framework\Mail\EmailMessageInterface;

/**
 * Selects the custom headers of a Magento email to forward to Lettermint.
 *
 * Lettermint builds the structural headers itself (From, To, Subject, Date,
 * Message-ID, MIME and Content-* headers) from the API fields, and adds any
 * header passed in `headers` to the email as it is. So only custom headers
 * are forwarded: `X-*` headers and a few standard list and auto-reply headers.
 * Lettermint's own control headers and headers whose name suggests a
 * credential are never forwarded.
 */
class EmailHeaderExtractor
{
    /** Standard headers that are safe and useful to forward. */
    private const FORWARDED = [
        'auto-submitted',
        'importance',
        'list-archive',
        'list-help',
        'list-id',
        'list-owner',
        'list-post',
        'list-subscribe',
        'list-unsubscribe',
        'list-unsubscribe-post',
        'precedence',
        'priority',
    ];

    /** Header names reserved for Lettermint (X-Lettermint-*, X-LM-*). */
    private const RESERVED = '/\Ax-(lettermint|lm)-/i';

    /** Header names that look like they carry a credential. */
    private const SENSITIVE = '/(auth|token|secret|passw|api[-_]?key|cookie|session|signature)/i';

    /** RFC 5322 field name: printable US-ASCII except the colon. */
    private const FIELD_NAME = '/\A[!-9;-~]+\z/';

    /**
     * @return array<string, string>
     */
    public function extractHeaders(EmailMessageInterface $message): array
    {
        $headers = [];
        foreach ($this->readHeaders($message) as [$name, $value]) {
            $name = trim($name);
            $value = trim(preg_replace('/\r?\n[ \t]+/', ' ', $value) ?? '');
            if (!$this->isForwarded($name) || $value === '' || preg_match('/[\r\n\0]/', $value)) {
                continue;
            }
            // The API takes one value per header; keep the first.
            foreach (array_keys($headers) as $existing) {
                if (strcasecmp($existing, $name) === 0) {
                    continue 2;
                }
            }
            $headers[$name] = $value;
        }

        return $headers;
    }

    private function isForwarded(string $name): bool
    {
        if (preg_match(self::FIELD_NAME, $name) !== 1
            || preg_match(self::RESERVED, $name) === 1
            || preg_match(self::SENSITIVE, $name) === 1
        ) {
            return false;
        }
        $lower = strtolower($name);

        return str_starts_with($lower, 'x-') || in_array($lower, self::FORWARDED, true);
    }

    /**
     * Reads the headers as [name, decoded value] pairs.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function readHeaders(EmailMessageInterface $message): array
    {
        // Magento 2.4.8+: the Symfony headers, with decoded values.
        if (method_exists($message, 'getSymfonyMessage')) {
            $pairs = [];
            foreach ($message->getSymfonyMessage()->getHeaders()->all() as $header) {
                $value = $header instanceof \Symfony\Component\Mime\Header\UnstructuredHeader
                    ? $header->getValue()
                    : $this->decode($header->getBodyAsString());
                $pairs[] = [$header->getName(), $value];
            }

            return $pairs;
        }

        // Magento 2.4.7 and older: Laminas headers as name => value (or list of values).
        $pairs = [];
        foreach ($message->getHeaders() as $name => $value) {
            if (is_int($name) && is_string($value) && str_contains($value, ':')) {
                // A "Name: value" line.
                [$name, $value] = explode(':', $value, 2);
                $value = $this->decode($value);
            }
            foreach (is_array($value) ? $value : [$value] as $single) {
                if (is_string($name) && is_scalar($single)) {
                    $pairs[] = [$name, (string)$single];
                }
            }
        }

        return $pairs;
    }

    /**
     * Decodes RFC 2047 encoded words.
     */
    private function decode(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return $value;
        }
        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded !== false ? $decoded : $value;
    }
}
