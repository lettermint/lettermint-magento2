<?php
declare(strict_types=1);

namespace Lettermint\Email\Test\Unit;

use Psr\Log\AbstractLogger;

/**
 * Collects log records in memory.
 */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param mixed $level
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string)$level, 'message' => (string)$message, 'context' => $context];
    }

    /**
     * Formats the records as Magento's Monolog handlers write them (with stack
     * traces), and as JSON, so tests can check what ends up in a log file.
     */
    public function formatted(): string
    {
        $line = new \Monolog\Formatter\LineFormatter(null, null, true, true, true);
        $json = new \Monolog\Formatter\JsonFormatter(includeStacktraces: true);
        $out = '';
        foreach ($this->records as $record) {
            if (class_exists(\Monolog\LogRecord::class)) { // Monolog 3
                $logRecord = new \Monolog\LogRecord(
                    new \DateTimeImmutable(),
                    'main',
                    \Monolog\Level::fromName($record['level']),
                    $record['message'],
                    $record['context']
                );
            } else { // Monolog 2
                $logRecord = [
                    'message' => $record['message'],
                    'context' => $record['context'],
                    'level' => \Monolog\Logger::toMonologLevel($record['level']),
                    'level_name' => strtoupper($record['level']),
                    'channel' => 'main',
                    'datetime' => new \DateTimeImmutable(),
                    'extra' => [],
                ];
            }
            $out .= $line->format($logRecord) . $json->format($logRecord);
            foreach ($record['context'] as $value) {
                if ($value instanceof \Throwable) {
                    $out .= (string)$value;
                }
            }
        }

        return $out;
    }
}
