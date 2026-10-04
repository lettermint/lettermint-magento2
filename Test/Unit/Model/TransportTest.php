<?php
declare(strict_types=1);

namespace Lettermint\Email\Test\Unit\Model;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Lettermint\Email\Model\LettermintClientFactory;
use Lettermint\Email\Model\Transport;
use Lettermint\Email\Model\TransportFactory;
use Lettermint\Email\Plugin\TransportSwitcher;
use Lettermint\Email\Service\EmailContentExtractor;
use Lettermint\Email\Service\EmailHeaderExtractor;
use Lettermint\Email\Test\Unit\ArrayLogger;
use Lettermint\Email\Test\Unit\MagentoMessages;
use Lettermint\Exceptions\LettermintException;
use Lettermint\Exceptions\ValidationException;
use Lettermint\Lettermint;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\Address;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Mail\MimeInterface;
use Magento\Framework\Mail\MimePart;
use Magento\Framework\Mail\TransportInterfaceFactory;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Sends through a mocked Guzzle handler: no request reaches the Lettermint API.
 */
final class TransportTest extends TestCase
{
    use MagentoMessages;

    private const TOKEN = 'lm_secretTestToken123';

    /** @var list<array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}> */
    private array $history = [];
    private MockHandler $mock;
    /** @var array<string, string> */
    private array $config;
    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->history = [];
        $this->mock = new MockHandler();
        $this->logger = new ArrayLogger();
        $this->config = [
            'lettermint_email/general/enabled' => '1',
            'lettermint_email/general/api_token' => 'encrypted-blob',
            'lettermint_email/routes/transactional_route' => 'outgoing',
            'lettermint_email/routes/newsletter_route' => 'broadcast',
        ];
    }

    public function testEachEmailIsSentWithOnlyItsOwnFields(): void
    {
        $this->mock->append($this->accepted('m1'), $this->accepted('m2'));
        $switcher = $this->switcher();
        $proceed = fn () => $this->fail('The default transport must not be used.');
        $factory = $this->createMock(TransportInterfaceFactory::class);

        $first = $switcher->aroundCreate($factory, $proceed, ['message' => $this->message(
            ['jane@example.com', 'john@example.com'],
            'Order #1',
            '<p>One</p>',
            cc: ['copy@example.com'],
            replyTo: [new Address('support@example.com', 'Support')]
        )]);
        $second = $switcher->aroundCreate($factory, $proceed, [
            'message' => $this->message(['eve@example.com'], 'Order #2', '<p>Two</p>'),
        ]);

        $this->assertNotSame($first, $second, 'Every email gets its own transport.');

        // Send in reverse order of creation: neither may see the other's message.
        $second->sendMessage();
        $first->sendMessage();

        $this->assertCount(2, $this->history);
        $this->assertSame([
            'from' => 'Example Shop <shop@example.com>',
            'to' => ['eve@example.com'],
            'subject' => 'Order #2',
            'html' => '<p>Two</p>',
            'route' => 'outgoing',
        ], $this->requestBody(0));
        $this->assertSame([
            'from' => 'Example Shop <shop@example.com>',
            'to' => ['jane@example.com', 'john@example.com'],
            'cc' => ['copy@example.com'],
            'reply_to' => ['support@example.com'],
            'subject' => 'Order #1',
            'html' => '<p>One</p>',
            'route' => 'outgoing',
        ], $this->requestBody(1));

        foreach ($this->history as $entry) {
            $request = $entry['request'];
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('https://api.lettermint.co/v1/send', (string)$request->getUri());
            $this->assertSame(self::TOKEN, $request->getHeaderLine('x-lettermint-token'));
            $this->assertFalse($request->hasHeader('Authorization'));
            $this->assertFalse($request->hasHeader('Idempotency-Key'));
            $this->assertEquals(LettermintClientFactory::TIMEOUT_SECONDS, $entry['options']['timeout']);
        }
        $this->assertSame('debug', $this->logger->records[0]['level']);
        $this->assertSame(['message_id' => 'm1', 'status' => 'pending'], $this->logger->records[0]['context']);
    }

    public function testDefaultTransportIsUsedWhenModuleIsDisabled(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn(false);
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects($this->never())->method('create');
        $switcher = new TransportSwitcher($scope, $this->logger, new TransportFactory($objectManager));

        $result = $switcher->aroundCreate(
            $this->createMock(TransportInterfaceFactory::class),
            fn (?array $data) => 'default transport',
            ['message' => $this->message(['jane@example.com'], 'Hi', '<p>Hi</p>')]
        );

        $this->assertSame('default transport', $result);
    }

    public function testApiErrorBecomesMailExceptionAndIsLoggedWithoutToken(): void
    {
        $this->mock->append(new Response(422, ['Content-Type' => 'application/json'], (string)json_encode([
            'message' => 'The from domain is not verified.',
            'errors' => ['from' => ['The from domain is not verified.']],
        ])));
        $transport = $this->transport();
        $transport->setMessage($this->message(['jane@example.com'], 'Hi', '<p>Hi</p>'));

        try {
            $transport->sendMessage();
            $this->fail('Expected a MailException.');
        } catch (MailException $e) {
            $this->assertStringContainsString('The from domain is not verified.', $e->getMessage());
            $this->assertInstanceOf(ValidationException::class, $e->getPrevious());
        }

        $error = $this->logger->records[0];
        $this->assertSame('error', $error['level']);
        $this->assertSame(422, $error['context']['status']);
        $this->assertSame(['from' => ['The from domain is not verified.']], $error['context']['errors']);
        $this->assertTokenNeverLogged();
    }

    public function testTimeoutBecomesMailException(): void
    {
        $this->mock->append(new ConnectException(
            'cURL error 28: Operation timed out',
            new Request('POST', 'https://api.lettermint.co/v1/send'),
            null,
            ['errno' => 28]
        ));
        $transport = $this->transport();
        $transport->setMessage($this->message(['jane@example.com'], 'Hi', '<p>Hi</p>'));

        try {
            $transport->sendMessage();
            $this->fail('Expected a MailException.');
        } catch (MailException $e) {
            $this->assertInstanceOf(LettermintException::class, $e->getPrevious());
        }
        $this->assertInstanceOf(LettermintException::class, $this->logger->records[0]['context']['exception']);
        $this->assertTokenNeverLogged();
    }

    public function testDisabledModuleDoesNotSend(): void
    {
        $this->config['lettermint_email/general/enabled'] = '0';
        $transport = $this->transport();
        $transport->setMessage($this->message(['jane@example.com'], 'Hi', '<p>Hi</p>'));

        $this->expectException(MailException::class);
        try {
            $transport->sendMessage();
        } finally {
            $this->assertCount(0, $this->history);
        }
    }

    public function testMissingTokenDoesNotSend(): void
    {
        unset($this->config['lettermint_email/general/api_token']);
        $transport = $this->transport();
        $transport->setMessage($this->message(['jane@example.com'], 'Hi', '<p>Hi</p>'));

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Lettermint API token is not configured.');
        try {
            $transport->sendMessage();
        } finally {
            $this->assertCount(0, $this->history);
        }
    }

    public function testAttachmentsAndCustomHeadersAreSent(): void
    {
        $this->mock->append($this->accepted('m1'));
        $pdf = "%PDF-1.4\n\x00binary";

        if (self::usesLaminasMime()) {
            // Magento 2.4.6 and 2.4.7: a module adds a Magento MimePart.
            $message = $this->magentoMessage([
                new MimePart('<p>Your invoice</p>'),
                new MimePart(
                    $pdf,
                    'application/pdf',
                    'invoice.pdf',
                    MimeInterface::DISPOSITION_ATTACHMENT,
                    MimeInterface::ENCODING_BASE64
                ),
            ], 'Invoice #1');
            $expectedType = 'application/pdf; charset=utf-8';
        } else {
            // Magento 2.4.8+: a module replaces the Symfony body.
            $message = $this->symfonyMessage(new \Symfony\Component\Mime\Part\Multipart\MixedPart(
                new \Symfony\Component\Mime\Part\TextPart('<p>Your invoice</p>', 'utf-8', 'html'),
                new \Symfony\Component\Mime\Part\DataPart($pdf, 'invoice.pdf', 'application/pdf')
            ));
            $message->getSymfonyMessage()->getHeaders()->addTextHeader('X-Order-Id', '000000123');
            $expectedType = 'application/pdf';
        }

        $transport = $this->transport();
        $transport->setMessage($message);
        $transport->sendMessage();

        $body = $this->requestBody(0);
        $this->assertSame('Example Shop <shop@example.com>', $body['from']);
        $this->assertSame(['jane@example.com'], $body['to']);
        $this->assertSame('<p>Your invoice</p>', $body['html']);
        $this->assertSame([
            ['filename' => 'invoice.pdf', 'content' => base64_encode($pdf), 'content_type' => $expectedType],
        ], $body['attachments']);
        if (self::usesLaminasMime()) {
            $this->assertArrayNotHasKey('headers', $body);
        } else {
            $this->assertSame(['X-Order-Id' => '000000123'], $body['headers']);
        }
    }

    public function testErrorWhileReadingTheMessageBecomesMailException(): void
    {
        $extractor = $this->createMock(EmailContentExtractor::class);
        $extractor->method('extractContent')->willThrowException(new \Error('Object could not be converted to string'));
        $transport = $this->transport($extractor);
        $transport->setMessage($this->message(['jane@example.com'], 'Hi', '<p>Hi</p>'));

        try {
            $transport->sendMessage();
            $this->fail('Expected a MailException.');
        } catch (MailException $e) {
            $this->assertStringContainsString('Object could not be converted to string', $e->getMessage());
            $this->assertInstanceOf(\Error::class, $e->getPrevious()?->getPrevious());
        }
        $this->assertCount(0, $this->history);
        $this->assertSame('error', $this->logger->records[0]['level']);
    }

    public function testRealClientFactoryUsesSendingTokenAndTimeout(): void
    {
        $client = (new LettermintClientFactory())->create(self::TOKEN);
        $info = $client->jsonSerialize();

        $this->assertEquals(15.0, $info['timeout']);
        $this->assertNotNull($info['sendingToken']);
        $this->assertNull($info['teamToken']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($info) . print_r($client, true));
    }

    private function transport(?EmailContentExtractor $extractor = null): Transport
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(fn (string $path) => (bool)($this->config[$path] ?? false));
        $scope->method('getValue')->willReturnCallback(fn (string $path) => $this->config[$path] ?? null);
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn(self::TOKEN);

        return new Transport(
            $scope,
            $this->logger,
            $encryptor,
            $extractor ?? new EmailContentExtractor($this->logger),
            $this->clientFactory(),
            new EmailHeaderExtractor()
        );
    }

    private function clientFactory(): LettermintClientFactory
    {
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        $http = new Client(['handler' => $stack]);
        $factory = $this->createMock(LettermintClientFactory::class);
        $factory->method('create')->willReturnCallback(function (string $token) use ($http): Lettermint {
            $this->assertSame(self::TOKEN, $token);

            return new Lettermint(
                sendingToken: $token,
                timeout: LettermintClientFactory::TIMEOUT_SECONDS,
                httpClient: $http
            );
        });

        return $factory;
    }

    private function switcher(): TransportSwitcher
    {
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('create')->willReturnCallback(fn () => $this->transport());
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn(true);

        return new TransportSwitcher($scope, $this->logger, new TransportFactory($objectManager));
    }

    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<Address>|null $replyTo
     */
    private function message(
        array $to,
        string $subject,
        string $html,
        array $cc = [],
        ?array $replyTo = null
    ): EmailMessageInterface {
        $message = $this->createMock(EmailMessageInterface::class);
        $message->method('getFrom')->willReturn([new Address('shop@example.com', 'Example Shop')]);
        $message->method('getTo')->willReturn(array_map(fn ($email) => new Address($email, 'Name'), $to));
        $message->method('getCc')->willReturn(array_map(fn ($email) => new Address($email, null), $cc));
        $message->method('getBcc')->willReturn([]);
        $message->method('getReplyTo')->willReturn($replyTo);
        $message->method('getSubject')->willReturn($subject);
        $message->method('getBody')->willReturn($this->htmlBody($html));

        return $message;
    }

    /**
     * An HTML body as the installed Magento version builds it: a Laminas MIME
     * message up to 2.4.7, a Symfony MIME part from 2.4.8.
     */
    private function htmlBody(string $html): object
    {
        if (class_exists(\Laminas\Mime\Message::class)) {
            $part = new \Laminas\Mime\Part($html);
            $part->setType('text/html');
            $mime = new \Laminas\Mime\Message();
            $mime->addPart($part);

            return $mime;
        }

        return new \Symfony\Component\Mime\Part\TextPart($html, 'utf-8', 'html');
    }

    private function accepted(string $messageId): Response
    {
        return new Response(
            202,
            ['Content-Type' => 'application/json'],
            (string)json_encode(['message_id' => $messageId, 'status' => 'pending'])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requestBody(int $index): array
    {
        return json_decode((string)$this->history[$index]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertTokenNeverLogged(): void
    {
        $this->assertNotEmpty($this->logger->records);
        $this->assertStringNotContainsString(self::TOKEN, $this->logger->formatted());
    }
}
