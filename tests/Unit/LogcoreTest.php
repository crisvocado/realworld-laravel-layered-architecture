<?php declare(strict_types=1);

namespace Tests\Unit;

use App\Logging\LogcoreContext;
use App\Logging\LogcoreFormatter;
use App\Logging\LogcoreHandler;
use App\Logging\LogcoreTransport;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LogcoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LogcoreContext::clear();
        LogcoreTransport::reset();
    }

    protected function tearDown(): void
    {
        LogcoreContext::clear();
        LogcoreTransport::reset();
        parent::tearDown();
    }

    public function testComputeInsertId(): void
    {
        $timestamp = '2026-08-17T18:30:00.000Z';
        $service = 'laravel';
        $severity = 'ERROR';
        $message = 'Test error message';
        $context = ['key' => 'value'];

        $insertId1 = LogcoreFormatter::computeInsertId($timestamp, $service, $severity, $message, $context);
        $insertId2 = LogcoreFormatter::computeInsertId($timestamp, $service, $severity, $message, $context);

        $this->assertSame($insertId1, $insertId2);
        $this->assertSame(32, strlen($insertId1));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $insertId1);
    }

    public function testFormatRecordWithException(): void
    {
        $dt = new DateTimeImmutable('2026-08-17 18:30:00.000', new DateTimeZone('UTC'));
        $rootException = new RuntimeException('Root cause exception');
        $wrapperException = new Exception('Wrapper exception', 0, $rootException);

        $record = new LogRecord(
            datetime: $dt,
            channel: 'local',
            level: Level::Error,
            message: 'An error occurred during operation',
            context: [
                'exception' => $wrapperException,
                'user_id' => 123,
                'password' => 'supersecret',
            ],
            extra: ['request_id' => 'req-12345'],
        );

        LogcoreContext::setTrace([
            'traceId' => '4bf92f3577b34da6a3ce929d0e0e4736',
            'spanId' => '00f067aa0ba902b7',
            'sampled' => true,
        ]);

        $formatted = LogcoreFormatter::formatRecord($record);

        $this->assertNotNull($formatted);
        $this->assertSame('2026-08-17T18:30:00.000Z', $formatted['timestamp']);
        $this->assertSame('ERROR', $formatted['severity']);
        $this->assertSame('An error occurred during operation', $formatted['message']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $formatted['insert_id']);
        $this->assertArrayNotHasKey('source_project', $formatted);

        // Verify Trace
        $this->assertArrayHasKey('trace', $formatted);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $formatted['trace']['traceId']);
        $this->assertSame('00f067aa0ba902b7', $formatted['trace']['spanId']);
        $this->assertTrue($formatted['trace']['sampled']);

        // Verify Error
        $this->assertArrayHasKey('error', $formatted);
        $this->assertSame(Exception::class, $formatted['error']['type']);
        $this->assertSame('Wrapper exception', $formatted['error']['message']);
        $this->assertNotEmpty($formatted['error']['stack']);

        $firstFrame = $formatted['error']['stack'][0];
        $this->assertArrayHasKey('function', $firstFrame);
        $this->assertArrayHasKey('file', $firstFrame);
        $this->assertArrayHasKey('line', $firstFrame);
        $this->assertArrayHasKey('column', $firstFrame);
        $this->assertArrayHasKey('inApp', $firstFrame);

        // Verify Redaction and Context
        $this->assertArrayHasKey('context', $formatted);
        $this->assertSame(123, $formatted['context']['user_id']);
        $this->assertSame('[REDACTED]', $formatted['context']['password']);
        $this->assertArrayNotHasKey('exception', $formatted['context']);

        // Verify Labels
        $this->assertArrayHasKey('labels', $formatted);
        $this->assertSame('req-12345', $formatted['labels']['request_id']);
    }

    public function testIgnoreSeverityBelowError(): void
    {
        $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $record = new LogRecord(
            datetime: $dt,
            channel: 'local',
            level: Level::Info,
            message: 'Informational message',
            context: [],
            extra: [],
        );

        $formatted = LogcoreFormatter::formatRecord($record);
        $this->assertNull($formatted);
    }

    public function testTransportBufferingAndFlushing(): void
    {
        $deliveredRequests = [];

        LogcoreTransport::setHttpHandlerForTesting(function (string $url, string $payload, string $apiKey) use (&$deliveredRequests): array {
            $deliveredRequests[] = [
                'url' => $url,
                'payload' => json_decode($payload, true),
                'apiKey' => $apiKey,
            ];

            return ['status' => 200, 'body' => '{"status":"ok"}', 'error' => ''];
        });

        $handler = new LogcoreHandler(Level::Error);

        $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $record = new LogRecord(
            datetime: $dt,
            channel: 'local',
            level: Level::Error,
            message: 'Buffered error test',
            context: ['item_id' => 999],
            extra: [],
        );

        $handler->handle($record);

        // Before flush, entry is in transport buffer
        $this->assertCount(1, LogcoreTransport::getBuffer());
        $this->assertNotNull(LogcoreTransport::getLastEmittedEntry());
        $this->assertSame('Buffered error test', LogcoreTransport::getLastEmittedEntry()['message']);

        // Flush
        LogcoreTransport::flush();

        $this->assertCount(0, LogcoreTransport::getBuffer());
        $this->assertCount(1, $deliveredRequests);

        $envelope = $deliveredRequests[0]['payload'];
        $this->assertSame(1, $envelope['schema_version']);
        $this->assertCount(1, $envelope['entries']);
        $this->assertSame('Buffered error test', $envelope['entries'][0]['message']);
        $this->assertSame('ERROR', $envelope['entries'][0]['severity']);
    }

    public function testCircuitBreaker(): void
    {
        LogcoreTransport::setHttpHandlerForTesting(function (): array {
            return ['status' => 500, 'body' => 'Internal Server Error', 'error' => 'Server error'];
        });

        // Trigger 10 failed flushes
        for ($i = 0; $i < 10; $i++) {
            LogcoreTransport::push([
                'timestamp' => '2026-08-17T18:30:00.000Z',
                'severity' => 'ERROR',
                'message' => 'Error ' . $i,
                'service' => 'laravel',
                'env' => 'local',
                'insert_id' => str_repeat('0', 32),
            ]);
            LogcoreTransport::flush();
        }

        // On 10th failure, circuit breaker opens and buffer is cleared
        $this->assertCount(0, LogcoreTransport::getBuffer());
    }
}
