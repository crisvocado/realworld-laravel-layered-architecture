<?php declare(strict_types=1);

namespace App\Http\Middleware;

use App\Logging\LogcoreContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogcoreTraceMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $traceparent = $request->header('traceparent');
        $traceId = null;
        $spanId = null;
        $sampled = true;

        if (is_string($traceparent) && preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/i', trim($traceparent), $matches)) {
            $traceId = strtolower($matches[2]);
            $spanId = strtolower($matches[3]);
            $flags = hexdec($matches[4]);
            $sampled = ($flags & 1) === 1;
        } else {
            $traceId = bin2hex(random_bytes(16));
            $spanId = bin2hex(random_bytes(8));
            $sampled = true;
        }

        LogcoreContext::setTrace($traceId, $spanId, $sampled);

        /** @var Response $response */
        $response = $next($request);

        $flagsHex = $sampled ? '01' : '00';
        $outgoingTraceparent = sprintf('00-%s-%s-%s', $traceId, $spanId, $flagsHex);
        $response->headers->set('traceparent', $outgoingTraceparent);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        LogcoreContext::clearTrace();
    }
}
