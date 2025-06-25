<?php
declare(strict_types=1);

namespace Lettermint\Email\Service;

use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\EmailMessageInterface;

class EmailContentExtractor
{
    /**
     * Extract email content from message using modern EmailMessageInterface approach
     * Compatible with Magento 2.3.3+ EmailMessageInterface
     */
    public function extractContent(EmailMessageInterface $message): array
    {
        $bodyVersions = [
            'text/html' => '',
            'text/plain' => ''
        ];

        $body = $message->getBody();
        
        if ($body instanceof \Laminas\Mime\Message) {
            // Handle multipart MIME message
            $parts = $body->getParts();
            foreach ($parts as $part) {
                $partType = $part->getType();
                if ($partType === 'text/html' || $partType === 'text/plain') {
                    $bodyVersions[$partType] = $part->getRawContent();
                }
            }
        } else {
            // Handle single body content 
            if ($body instanceof \Symfony\Component\Mime\Part\TextPart) {
                $bodyContent = $body->getBody();
                $mediaSubtype = $body->getMediaSubtype();
                
                if ($mediaSubtype === 'html') {
                    $bodyVersions['text/html'] = $bodyContent;
                } else {
                    $bodyVersions['text/plain'] = $bodyContent;
                }
            } else {
                // Simple fallback
                $bodyContent = (string) $body;
                $bodyVersions['text/plain'] = $bodyContent;
            }
        }

        // Ensure we have some content
        if (empty($bodyVersions['text/html']) && empty($bodyVersions['text/plain'])) {
            throw new MailException(__('No email body content found'));
        }

        return [
            'html' => $bodyVersions['text/html'] ?: null,
            'text' => $bodyVersions['text/plain'] ?: null
        ];
    }
}