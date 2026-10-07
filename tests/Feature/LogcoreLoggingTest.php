<?php declare(strict_types=1);

namespace Tests\Feature;

use App\Logcore\LogcoreHandler;
use App\Logcore\LogcoreTraceContext;
use App\Logcore\LogcoreTraceMiddleware;
use App\Logcore\LogcoreTransport;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;

final class LogcoreLoggingTest extends TestCase
{
    protected function tearDown(): void
    {
        LogcoreTransport::resetInstance();
        LogcoreTraceContext::clear();
        parent::tearDown();
    }

    public function testLogcoreHandlerFormatsEntryWithException(): void
    {
        $captured = null;
        $transport = LogcoreTransport::getInstance();
        $transport->setCaptureCallback(function (string $json, array $payload) use (&$captured) {
            $captured = $payload;
        });

        $syntheticException = new RuntimeException('Test exception message');
        Log::error('Test error message for Logcore', [
            'probe' => true,
            'exception' => $syntheticException,
        ]);

        $transport->flush();

        $this->assertNotNull($captured);
        $this->assertSame(1, $captured['schema_version']);
        $this->assertCount(1, $captured['entries']);

        $entry = $captured['entries'][0];
        $this->assertSame('ERROR', $entry['severity']);
        $this->assertSame('Test error message for Logcore', $entry['message']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $entry['insert_id']);
        $this->assertContains($entry['env'], ['test', 'prod', 'dev', 'local']);
        $this->assertNotEmpty($entry['timestamp']);

        $this->assertArrayHasKey('error', $entry);
        $this->assertSame('RuntimeException', $entry['error']['type']);
        $this->assertSame('Test exception message', $entry['error']['message']);
        $this->assertIsArray($entry['error']['stack']);
        $this->assertNotEmpty($entry['error']['stack']);
        $this->assertArrayHasKey('inApp', $entry['error']['stack'][0]);
        $this->assertArrayHasKey('function', $entry['error']['stack'][0]);

        $this->assertArrayHasKey('context', $entry);
        $this->assertTrue($entry['context']['probe']);
    }

    public function testTraceparentParsingAndContext(): void
    {
        $traceparent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $middleware = new LogcoreTraceMiddleware();

        $request = Request::create('/up', 'GET', [], [], [], [
            'HTTP_TRACEPARENT' => $traceparent,
        ]);

        $response = $middleware->handle($request, function ($req) {
            $current = LogcoreTraceContext::get();
            $this->assertNotNull($current);
            $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $current['traceId']);
            $this->assertSame('00f067aa0ba902b7', $current['spanId']);
            $this->assertTrue($current['sampled']);

            return new Response('OK', 200);
        });

        $this->assertTrue($response->headers->has('traceparent'));
        $this->assertSame($traceparent, $response->headers->get('traceparent'));
    }

    public function testDeterministicInsertId(): void
    {
        $handler = new LogcoreHandler();
        $record1 = new LogRecord(
            datetime: new \DateTimeImmutable('2026-08-17T18:30:00.000Z'),
            channel: 'laravel',
            level: Level::Error,
            message: 'Same message',
            context: ['key' => 'value'],
        );

        $record2 = new LogRecord(
            datetime: new \DateTimeImmutable('2026-08-17T18:30:00.000Z'),
            channel: 'laravel',
            level: Level::Error,
            message: 'Same message',
            context: ['key' => 'value'],
        );

        $entry1 = $handler->formatEntry($record1);
        $entry2 = $handler->formatEntry($record2);

        $this->assertSame($entry1['insert_id'], $entry2['insert_id']);
    }
}
