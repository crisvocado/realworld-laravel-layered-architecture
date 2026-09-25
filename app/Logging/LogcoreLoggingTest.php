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

class LogcoreLoggingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LogcoreTraceContext::clear();
        LogcoreTransport::resetInstance();
    }

    protected function tearDown(): void
    {
        LogcoreTraceContext::clear();
        LogcoreTransport::resetInstance();
        parent::tearDown();
    }

    public function testTraceContextParsingAndGeneration(): void
    {
        // Valid traceparent
        $traceparent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        LogcoreTraceContext::setTraceparent($traceparent);

        $trace = LogcoreTraceContext::getTrace();
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $trace['traceId']);
        $this->assertSame('00f067aa0ba902b7', $trace['spanId']);
        $this->assertTrue($trace['sampled']);
        $this->assertSame($traceparent, LogcoreTraceContext::generateTraceparent());

        // Reset and generate
        LogcoreTraceContext::clear();
        $generated = LogcoreTraceContext::getTrace();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $generated['traceId']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $generated['spanId']);
        $this->assertTrue($generated['sampled']);
    }

    public function testFormatterWithExceptionMatchesWireShape(): void
    {
        LogcoreTraceContext::setTrace('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', true);

        $rootException = new RuntimeException('Root database error');
        $wrappedException = new Exception('Service layer failure', 0, $rootException);

        $record = new LogRecord(
            datetime: new DateTimeImmutable('2026-08-17T18:30:00.000Z'),
            channel: 'testing',
            level: Level::Error,
            message: 'checkout failed',
            context: [
                'exception' => $wrappedException,
                'user_id' => 'user-123',
            ],
            extra: [
                'ip' => '127.0.0.1',
            ]
        );

        $formatter = new LogcoreFormatter();
        $entry = $formatter->formatRecord($record);

        // Required top-level keys
        $this->assertSame('2026-08-17T18:30:00.000Z', $entry['timestamp']);
        $this->assertSame('ERROR', $entry['severity']);
        $this->assertSame('checkout failed', $entry['message']);
        $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9._-]{0,62}$/', $entry['service']);
        $this->assertContains($entry['env'], ['prod', 'staging', 'dev', 'test', 'local']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $entry['insert_id']);

        // Trace
        $this->assertIsArray($entry['trace']);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $entry['trace']['traceId']);
        $this->assertSame('00f067aa0ba902b7', $entry['trace']['spanId']);
        $this->assertTrue($entry['trace']['sampled']);

        // Error
        $this->assertIsArray($entry['error']);
        $this->assertSame('Exception', $entry['error']['type']);
        $this->assertSame('Service layer failure', $entry['error']['message']);
        $this->assertIsArray($entry['error']['stack']);
        $this->assertNotEmpty($entry['error']['stack']);

        $firstFrame = $entry['error']['stack'][0];
        $this->assertArrayHasKey('function', $firstFrame);
        $this->assertArrayHasKey('file', $firstFrame);
        $this->assertArrayHasKey('line', $firstFrame);
        $this->assertArrayHasKey('column', $firstFrame);
        $this->assertArrayHasKey('inApp', $firstFrame);
        $this->assertIsBool($firstFrame['inApp']);

        // Labels
        $this->assertIsArray($entry['labels']);
        $this->assertSame('testing', $entry['labels']['channel']);
        $this->assertSame('127.0.0.1', $entry['labels']['ip']);

        // Context
        $this->assertIsArray($entry['context']);
        $this->assertSame('user-123', $entry['context']['user_id']);
        $this->assertArrayNotHasKey('exception', $entry['context']);
    }

    public function testSanitizers(): void
    {
        $this->assertSame('laravel', LogcoreFormatter::sanitizeService(null));
        $this->assertSame('my-service-app', LogcoreFormatter::sanitizeService('My Service App!'));
        $this->assertSame('service-123', LogcoreFormatter::sanitizeService('___service-123'));

        $this->assertSame('prod', LogcoreFormatter::sanitizeEnv('production'));
        $this->assertSame('prod', LogcoreFormatter::sanitizeEnv('PROD'));
        $this->assertSame('staging', LogcoreFormatter::sanitizeEnv('stage'));
        $this->assertSame('dev', LogcoreFormatter::sanitizeEnv('development'));
        $this->assertSame('test', LogcoreFormatter::sanitizeEnv('testing'));
        $this->assertSame('local', LogcoreFormatter::sanitizeEnv('local'));
        $this->assertSame('local', LogcoreFormatter::sanitizeEnv('unknown-env'));
    }

    public function testTransportBufferingAndEnvelope(): void
    {
        $transport = new LogcoreTransport();
        $transport->resetCircuitBreaker();

        $sentPayloads = [];
        $transport->setSender(function (array $payload) use (&$sentPayloads) {
            $sentPayloads[] = $payload;
            return true;
        });

        $dummyEntry = [
            'timestamp' => '2026-08-17T18:30:00.000Z',
            'severity' => 'ERROR',
            'message' => 'test message',
            'service' => 'laravel',
            'env' => 'test',
            'insert_id' => '7c2e1a0b9d8f6e4c2a0b8d6f4e2c0a8b',
            'trace' => [
                'traceId' => '4bf92f3577b34da6a3ce929d0e0e4736',
                'spanId' => '00f067aa0ba902b7',
                'sampled' => true,
            ],
        ];

        // Push 5 entries (does not auto-flush yet, threshold is 20)
        for ($i = 0; $i < 5; $i++) {
            $transport->push($dummyEntry);
        }

        $this->assertCount(5, $transport->getBuffer());
        $this->assertCount(0, $sentPayloads);

        // Explicit flush
        $transport->flush();
        $this->assertCount(0, $transport->getBuffer());
        $this->assertCount(1, $sentPayloads);

        $payload = $sentPayloads[0];
        $this->assertSame(1, $payload['schema_version']);
        $this->assertIsArray($payload['entries']);
        $this->assertCount(5, $payload['entries']);
    }

    public function testTransportAutoFlushAtThreshold(): void
    {
        $transport = new LogcoreTransport();
        $sentPayloads = [];
        $transport->setSender(function (array $payload) use (&$sentPayloads) {
            $sentPayloads[] = $payload;
            return true;
        });

        $dummyEntry = [
            'timestamp' => '2026-08-17T18:30:00.000Z',
            'severity' => 'ERROR',
            'message' => 'batch test',
            'service' => 'laravel',
            'env' => 'test',
            'insert_id' => '7c2e1a0b9d8f6e4c2a0b8d6f4e2c0a8b',
            'trace' => [
                'traceId' => '4bf92f3577b34da6a3ce929d0e0e4736',
                'spanId' => '00f067aa0ba902b7',
                'sampled' => true,
            ],
        ];

        for ($i = 0; $i < 20; $i++) {
            $transport->push($dummyEntry);
        }

        $this->assertCount(1, $sentPayloads);
        $this->assertCount(20, $sentPayloads[0]['entries']);
    }

    public function testCircuitBreaker(): void
    {
        $transport = new LogcoreTransport();
        $transport->setSender(function (array $payload) {
            return false; // Fail
        });

        $dummyEntry = [
            'timestamp' => '2026-08-17T18:30:00.000Z',
            'severity' => 'ERROR',
            'message' => 'circuit test',
            'service' => 'laravel',
            'env' => 'test',
            'insert_id' => '7c2e1a0b9d8f6e4c2a0b8d6f4e2c0a8b',
            'trace' => [
                'traceId' => '4bf92f3577b34da6a3ce929d0e0e4736',
                'spanId' => '00f067aa0ba902b7',
                'sampled' => true,
            ],
        ];

        for ($i = 0; $i < 10; $i++) {
            $transport->push($dummyEntry);
            $transport->flush();
        }

        $this->assertSame(10, $transport->getConsecutiveFailures());

        // Further pushes should not buffer
        $transport->push($dummyEntry);
        $this->assertEmpty($transport->getBuffer());
    }

    public function testHandlerAndLoggerFactory(): void
    {
        $transport = new LogcoreTransport();
        $sentPayloads = [];
        $transport->setSender(function (array $payload) use (&$sentPayloads) {
            $sentPayloads[] = $payload;
            return true;
        });

        $handler = new LogcoreHandler(
            level: Level::Error,
            transport: $transport
        );

        $factory = new CreateLogcoreLogger();
        $logger = $factory(['level' => 'error']);
        $logger->setHandlers([$handler]);

        // Info log should be ignored
        $logger->info('Ignored info log');
        $this->assertCount(0, $transport->getBuffer());

        // Error log should be captured
        $logger->error('Captured error log');
        $this->assertCount(1, $transport->getBuffer());

        $handler->close();
        $this->assertCount(1, $sentPayloads);
        $this->assertSame('Captured error log', $sentPayloads[0]['entries'][0]['message']);
        $this->assertSame('ERROR', $sentPayloads[0]['entries'][0]['severity']);
    }

    public function testTraceMiddleware(): void
    {
        $middleware = new LogcoreTraceMiddleware();
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('traceparent', '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');

        $response = $middleware->handle($request, function (Request $req) {
            $trace = LogcoreTraceContext::getTrace();
            $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $trace['traceId']);
            return new Response('OK');
        });

        $this->assertSame('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', $response->headers->get('traceparent'));
    }
}
