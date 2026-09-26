<?php declare(strict_types=1);

namespace App\Logging;

use App\Http\Middleware\LogcoreTraceMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class LogcoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LogcoreTraceContext::reset();
        LogcoreTransport::resetInstance();
    }

    protected function tearDown(): void
    {
        LogcoreTraceContext::reset();
        LogcoreTransport::resetInstance();
        parent::tearDown();
    }

    public function testTraceContextParseAndGenerate(): void
    {
        $validHeader = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        LogcoreTraceContext::setTraceFromHeader($validHeader);

        $trace = LogcoreTraceContext::getCurrentTrace();
        $this->assertNotNull($trace);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $trace['traceId']);
        $this->assertSame('00f067aa0ba902b7', $trace['spanId']);
        $this->assertTrue($trace['sampled']);

        $generated = LogcoreTraceContext::generateTraceparentHeader();
        $this->assertSame($validHeader, $generated);
    }

    public function testTraceContextFallbackOnInvalid(): void
    {
        LogcoreTraceContext::setTraceFromHeader('invalid-trace-header');

        $trace = LogcoreTraceContext::getCurrentTrace();
        $this->assertNotNull($trace);
        $this->assertSame(32, strlen($trace['traceId']));
        $this->assertSame(16, strlen($trace['spanId']));
        $this->assertTrue($trace['sampled']);
    }

    public function testLogcoreHandlerFormatsLogRecord(): void
    {
        LogcoreTraceContext::setTrace('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', true);

        $handler = new LogcoreHandler(Level::Error);

        $exception = new \RuntimeException('Database connection lost');
        $record = new LogRecord(
            datetime: new \DateTimeImmutable('2026-08-17T18:30:00.000Z'),
            channel: 'testing',
            level: Level::Error,
            message: 'An error occurred during transaction',
            context: [
                'exception' => $exception,
                'user_id' => 'user_123',
            ],
            extra: [
                'tag' => 'auth',
            ]
        );

        $entry = $handler->formatLogRecord($record);

        $this->assertSame('2026-08-17T18:30:00.000Z', $entry['timestamp']);
        $this->assertSame('ERROR', $entry['severity']);
        $this->assertSame('An error occurred during transaction', $entry['message']);
        $this->assertSame(32, strlen($entry['insert_id']));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $entry['insert_id']);

        $this->assertArrayHasKey('trace', $entry);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $entry['trace']['traceId']);
        $this->assertSame('00f067aa0ba902b7', $entry['trace']['spanId']);
        $this->assertTrue($entry['trace']['sampled']);

        $this->assertArrayHasKey('error', $entry);
        $this->assertSame('RuntimeException', $entry['error']['type']);
        $this->assertSame('Database connection lost', $entry['error']['message']);
        $this->assertNotEmpty($entry['error']['stack']);
        $this->assertArrayHasKey('function', $entry['error']['stack'][0]);
        $this->assertArrayHasKey('file', $entry['error']['stack'][0]);
        $this->assertArrayHasKey('line', $entry['error']['stack'][0]);
        $this->assertArrayHasKey('column', $entry['error']['stack'][0]);
        $this->assertArrayHasKey('inApp', $entry['error']['stack'][0]);

        $this->assertArrayHasKey('labels', $entry);
        $this->assertSame('auth', $entry['labels']['tag']);

        $this->assertArrayHasKey('context', $entry);
        $this->assertSame('user_123', $entry['context']['user_id']);
        $this->assertArrayNotHasKey('exception', $entry['context']);

        $this->assertArrayNotHasKey('source_project', $entry);
    }

    public function testExceptionChainFollowsToRootCauseFirst(): void
    {
        $inner = new \InvalidArgumentException('Root invalid input');
        $outer = new \RuntimeException('Outer service wrapper', 0, $inner);

        $handler = new LogcoreHandler(Level::Error);
        $record = new LogRecord(
            datetime: new \DateTimeImmutable('2026-08-17T18:30:00.000Z'),
            channel: 'testing',
            level: Level::Error,
            message: 'Service call failed',
            context: ['exception' => $outer]
        );

        $entry = $handler->formatLogRecord($record);

        $this->assertSame('RuntimeException', $entry['error']['type']);
        $this->assertSame('Outer service wrapper', $entry['error']['message']);

        // First frame in stack must be from the inner (root) exception throw site
        $this->assertNotEmpty($entry['error']['stack']);
        $firstFrame = $entry['error']['stack'][0];
        $this->assertSame($inner->getFile(), $firstFrame['file']);
        $this->assertSame($inner->getLine(), $firstFrame['line']);
    }

    public function testTransportBatchingAndSenderStub(): void
    {
        $capturedPayloads = [];
        LogcoreTransport::setSender(function (array $payload) use (&$capturedPayloads): bool {
            $capturedPayloads[] = $payload;
            return true;
        });

        $transport = LogcoreTransport::getInstance();

        $entry = [
            'timestamp' => '2026-08-17T18:30:00.000Z',
            'severity' => 'ERROR',
            'message' => 'test error',
            'service' => 'realworld-backend',
            'env' => 'local',
            'insert_id' => '7c2e1a0b9d8f6e4c2a0b8d6f4e2c0a8b',
        ];

        $transport->enqueue($entry);
        $transport->flush();

        $this->assertCount(1, $capturedPayloads);
        $this->assertSame(1, $capturedPayloads[0]['schema_version']);
        $this->assertCount(1, $capturedPayloads[0]['entries']);
        $this->assertSame('test error', $capturedPayloads[0]['entries'][0]['message']);
    }

    public function testNormalizationHelpers(): void
    {
        $this->assertSame('realworld-backend', LogcoreTransport::normalizeService('realworld-backend'));
        $this->assertSame('my-custom-service', LogcoreTransport::normalizeService('My Custom Service!'));
        $this->assertSame('prod', LogcoreTransport::normalizeEnv('production'));
        $this->assertSame('staging', LogcoreTransport::normalizeEnv('stage'));
        $this->assertSame('test', LogcoreTransport::normalizeEnv('testing'));
        $this->assertSame('dev', LogcoreTransport::normalizeEnv('development'));
        $this->assertSame('local', LogcoreTransport::normalizeEnv('local'));
        $this->assertSame('dev', LogcoreTransport::normalizeEnv('unknown'));
    }

    public function testTraceMiddleware(): void
    {
        $middleware = new LogcoreTraceMiddleware();
        $request = Request::create('/test', 'GET');
        $request->headers->set('traceparent', '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');

        $response = $middleware->handle($request, function ($req) {
            $trace = LogcoreTraceContext::getCurrentTrace();
            $this->assertNotNull($trace);
            $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $trace['traceId']);
            return new Response('OK');
        });

        $this->assertTrue($response->headers->has('traceparent'));
        $this->assertSame('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', $response->headers->get('traceparent'));
    }

    public function testLaravelLoggingConfiguration(): void
    {
        $app = new \Illuminate\Foundation\Application(__DIR__ . '/../..');
        \Illuminate\Container\Container::setInstance($app);

        $config = require __DIR__ . '/../../config/logging.php';

        $this->assertArrayHasKey('logcore', $config['channels']);
        $this->assertSame('monolog', $config['channels']['logcore']['driver']);
        $this->assertSame(LogcoreHandler::class, $config['channels']['logcore']['handler']);

        $this->assertContains('logcore', $config['channels']['stack']['channels']);
    }

    public function testWireShapeContractConformance(): void
    {
        $capturedPayloads = [];
        LogcoreTransport::setSender(function (array $payload) use (&$capturedPayloads): bool {
            $capturedPayloads[] = $payload;
            return true;
        });

        $handler = new LogcoreHandler(Level::Error);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable('2026-08-17T18:30:00.000Z'),
            channel: 'testing',
            level: Level::Error,
            message: 'checkout failed',
            context: [
                'user_id' => 'u1',
            ]
        );

        $entry = $handler->formatLogRecord($record);

        // Required top-level fields
        $this->assertArrayHasKey('timestamp', $entry);
        $this->assertArrayHasKey('severity', $entry);
        $this->assertArrayHasKey('message', $entry);
        $this->assertArrayHasKey('service', $entry);
        $this->assertArrayHasKey('env', $entry);
        $this->assertArrayHasKey('insert_id', $entry);

        // Field formats and enums per Logcore contract
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $entry['timestamp']);
        $this->assertContains($entry['severity'], ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY']);
        $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9._-]{0,62}$/', $entry['service']);
        $this->assertContains($entry['env'], ['prod', 'staging', 'dev', 'test', 'local']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $entry['insert_id']);

        // HTTP external backend must not send source_project
        $this->assertArrayNotHasKey('source_project', $entry);
    }
}
