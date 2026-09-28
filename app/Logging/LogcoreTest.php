<?php declare(strict_types=1);

namespace App\Logging;

use App\Http\Middleware\LogcoreTraceMiddleware;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\Request;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class LogcoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LogcoreContext::reset();
    }

    protected function tearDown(): void
    {
        LogcoreContext::reset();
        parent::tearDown();
    }

    public function testFormatEntryBasic(): void
    {
        $transport = new LogcoreTransport(enabled: false);
        $handler = new LogcoreHandler(Level::Error, true, $transport);

        $now = new DateTimeImmutable('2026-08-17 18:30:00.123456 UTC');
        $record = new LogRecord(
            datetime: $now,
            channel: 'testing',
            level: Level::Error,
            message: 'checkout failed',
            context: ['order_id' => 1234],
            extra: []
        );

        $entry = $handler->formatEntry($record);

        $this->assertSame('2026-08-17T18:30:00.123Z', $entry['timestamp']);
        $this->assertSame('ERROR', $entry['severity']);
        $this->assertSame('checkout failed', $entry['message']);
        $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9._-]{0,62}$/', $entry['service']);
        $this->assertContains($entry['env'], ['prod', 'staging', 'dev', 'test', 'local']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $entry['insert_id']);
        $this->assertSame(['order_id' => 1234], $entry['context']);
        $this->assertArrayNotHasKey('error', $entry);
        $this->assertArrayNotHasKey('trace', $entry);
    }

    public function testFormatEntryWithTraceAndLabels(): void
    {
        $transport = new LogcoreTransport(enabled: false);
        $handler = new LogcoreHandler(Level::Error, true, $transport);

        LogcoreContext::setTrace(
            '4bf92f3577b34da6a3ce929d0e0e4736',
            '00f067aa0ba902b7',
            true
        );
        LogcoreContext::addLabel('tier', 'frontend');

        $record = new LogRecord(
            datetime: new DateTimeImmutable('2026-08-17 18:30:00 UTC'),
            channel: 'testing',
            level: Level::Critical,
            message: 'critical system error',
            context: [],
            extra: []
        );

        $entry = $handler->formatEntry($record);

        $this->assertSame('CRITICAL', $entry['severity']);
        $this->assertArrayHasKey('trace', $entry);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $entry['trace']['traceId']);
        $this->assertSame('00f067aa0ba902b7', $entry['trace']['spanId']);
        $this->assertTrue($entry['trace']['sampled']);

        $this->assertArrayHasKey('labels', $entry);
        $this->assertSame('frontend', $entry['labels']['tier']);
    }

    public function testFormatEntryWithErrorAndExceptionChain(): void
    {
        $transport = new LogcoreTransport(enabled: false);
        $handler = new LogcoreHandler(Level::Error, true, $transport);

        $rootException = new RuntimeException('Database query failed', 500);
        $wrappedException = new Exception('User operation failed', 0, $rootException);

        $record = new LogRecord(
            datetime: new DateTimeImmutable('2026-08-17 18:30:00 UTC'),
            channel: 'testing',
            level: Level::Error,
            message: 'An error occurred',
            context: ['exception' => $wrappedException, 'user_id' => 42],
            extra: []
        );

        $entry = $handler->formatEntry($record);

        $this->assertArrayHasKey('error', $entry);
        $this->assertSame('Exception', $entry['error']['type']);
        $this->assertSame('User operation failed', $entry['error']['message']);
        $this->assertIsArray($entry['error']['stack']);
        $this->assertNotEmpty($entry['error']['stack']);

        $firstFrame = $entry['error']['stack'][0];
        $this->assertArrayHasKey('function', $firstFrame);
        $this->assertArrayHasKey('file', $firstFrame);
        $this->assertArrayHasKey('line', $firstFrame);
        $this->assertArrayHasKey('column', $firstFrame);
        $this->assertArrayHasKey('inApp', $firstFrame);
        $this->assertIsBool($firstFrame['inApp']);
        $this->assertSame(0, $firstFrame['column']);

        // Context should not contain the exception object
        $this->assertSame(['user_id' => 42], $entry['context']);
    }

    public function testDeterministicInsertId(): void
    {
        $transport = new LogcoreTransport(enabled: false);
        $handler = new LogcoreHandler(Level::Error, true, $transport);

        $record1 = new LogRecord(
            datetime: new DateTimeImmutable('2026-08-17 18:30:00 UTC'),
            channel: 'testing',
            level: Level::Error,
            message: 'test error',
            context: ['a' => 1, 'b' => 2],
            extra: []
        );

        $record2 = new LogRecord(
            datetime: new DateTimeImmutable('2026-08-17 18:30:00 UTC'),
            channel: 'testing',
            level: Level::Error,
            message: 'test error',
            context: ['b' => 2, 'a' => 1], // Different key order
            extra: []
        );

        $record3 = new LogRecord(
            datetime: new DateTimeImmutable('2026-08-17 18:30:00 UTC'),
            channel: 'testing',
            level: Level::Error,
            message: 'different error',
            context: ['a' => 1, 'b' => 2],
            extra: []
        );

        $entry1 = $handler->formatEntry($record1);
        $entry2 = $handler->formatEntry($record2);
        $entry3 = $handler->formatEntry($record3);

        $this->assertSame($entry1['insert_id'], $entry2['insert_id']);
        $this->assertNotSame($entry1['insert_id'], $entry3['insert_id']);
        $this->assertSame(32, strlen($entry1['insert_id']));
    }

    public function testTransportBufferingAndDelivery(): void
    {
        $sentPayloads = [];
        $transport = new LogcoreTransport(
            endpoint: 'https://test.logcore.dev',
            apiKey: 'test-key',
            enabled: true
        );

        $transport->setHttpClient(function (string $url, array $headers, string $body) use (&$sentPayloads) {
            $sentPayloads[] = [
                'url' => $url,
                'headers' => $headers,
                'body' => json_decode($body, true),
            ];
            return ['statusCode' => 200, 'body' => '{"status":"ok"}'];
        });

        $entry = [
            'timestamp' => '2026-08-17T18:30:00.000Z',
            'severity' => 'ERROR',
            'message' => 'test message',
            'service' => 'laravel',
            'env' => 'test',
            'insert_id' => '7c2e1a0b9d8f6e4c2a0b8d6f4e2c0a8b',
        ];

        $transport->push($entry);
        $this->assertCount(1, $transport->getBuffer());

        $transport->flush();
        $this->assertCount(0, $transport->getBuffer());
        $this->assertCount(1, $sentPayloads);

        $this->assertSame('https://test.logcore.dev/v1/logs', $sentPayloads[0]['url']);
        $this->assertSame('test-key', $sentPayloads[0]['headers']['x-api-key']);
        $this->assertSame(1, $sentPayloads[0]['body']['schema_version']);
        $this->assertCount(1, $sentPayloads[0]['body']['entries']);
        $this->assertSame('test message', $sentPayloads[0]['body']['entries'][0]['message']);
    }

    public function testTransportPermanentRejectionNoRetry(): void
    {
        $calls = 0;
        $transport = new LogcoreTransport(
            endpoint: 'https://test.logcore.dev',
            apiKey: 'test-key',
            enabled: true
        );

        $transport->setHttpClient(function () use (&$calls) {
            $calls++;
            return ['statusCode' => 400, 'body' => '{"error":"invalid schema"}'];
        });

        $result = $transport->sendBatch([
            ['message' => 'test'],
        ]);

        $this->assertFalse($result);
        $this->assertSame(1, $calls); // Must not retry on 400
        $this->assertSame(1, $transport->getConsecutiveFailures());
    }

    public function testCircuitBreakerTripsAfter10Failures(): void
    {
        $transport = new LogcoreTransport(
            endpoint: 'https://test.logcore.dev',
            apiKey: 'test-key',
            enabled: true
        );
        $transport->setRetryDelayMultiplier(0.0);

        $transport->setHttpClient(function () {
            return ['statusCode' => 500, 'body' => '{"error":"internal server error"}'];
        });

        for ($i = 0; $i < 10; $i++) {
            $transport->sendBatch([['message' => "test $i"]]);
        }

        $this->assertTrue($transport->isCircuitBroken());
        $this->assertSame(10, $transport->getConsecutiveFailures());

        // Further pushes should be discarded
        $transport->push(['message' => 'discarded']);
        $this->assertEmpty($transport->getBuffer());
    }

    public function testTraceMiddleware(): void
    {
        $middleware = new LogcoreTraceMiddleware();
        $request = Request::create('/test', 'GET');
        $request->headers->set('traceparent', '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');

        $response = $middleware->handle($request, function ($req) {
            $trace = LogcoreContext::getTrace();
            $this->assertNotNull($trace);
            $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $trace['traceId']);
            $this->assertSame('00f067aa0ba902b7', $trace['spanId']);
            $this->assertTrue($trace['sampled']);

            return new Response('OK');
        });

        $this->assertTrue($response->headers->has('traceparent'));
        $this->assertStringStartsWith('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-', $response->headers->get('traceparent'));

        $middleware->terminate($request, $response);
        $this->assertNull(LogcoreContext::getTrace());
    }

    public function testTraceMiddlewareGeneratesTraceWhenHeaderMissing(): void
    {
        $middleware = new LogcoreTraceMiddleware();
        $request = Request::create('/test', 'GET');

        $response = $middleware->handle($request, function ($req) {
            $trace = LogcoreContext::getTrace();
            $this->assertNotNull($trace);
            $this->assertSame(32, strlen($trace['traceId']));
            $this->assertSame(16, strlen($trace['spanId']));
            $this->assertTrue($trace['sampled']);

            return new Response('OK');
        });

        $this->assertTrue($response->headers->has('traceparent'));
        $middleware->terminate($request, $response);
    }

    public function testHandlerUsesSingletonTransportByDefault(): void
    {
        $transport = LogcoreTransport::getInstance();
        $handler = new LogcoreHandler();

        $ref = new \ReflectionProperty($handler, 'transport');
        $this->assertSame($transport, $ref->getValue($handler));
    }
}
