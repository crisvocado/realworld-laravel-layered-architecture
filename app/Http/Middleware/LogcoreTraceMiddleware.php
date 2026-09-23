<?php declare(strict_types=1);

namespace App\Http\Middleware;

use App\Logging\LogcoreContext;
use App\Logging\LogcoreTransport;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogcoreTraceMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $traceparent = $request->header('traceparent') ?? $request->header('Traceparent');
        $traceId = null;
        $spanId = null;
        $sampled = true;

        if (is_string($traceparent) && preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/i', $traceparent, $matches)) {
            $traceId = strtolower($matches[1]);
            $spanId = bin2hex(random_bytes(8));
            $sampled = ($matches[3] === '01');
        } else {
            $traceId = bin2hex(random_bytes(16));
            $spanId = bin2hex(random_bytes(8));
            $sampled = true;
        }

        LogcoreContext::setTrace([
            'traceId' => $traceId,
            'spanId' => $spanId,
            'sampled' => $sampled,
        ]);

        $response = $next($request);

        $flags = $sampled ? '01' : '00';
        $response->headers->set('traceparent', sprintf('00-%s-%s-%s', $traceId, $spanId, $flags));

        return $response;
    }

    /**
     * Perform any final actions for the request lifecycle after response is sent.
     */
    public function terminate(Request $request, Response $response): void
    {
        try {
            LogcoreTransport::flush();
        } catch (\Throwable) {
            // Never disrupt response termination
        } finally {
            LogcoreContext::clear();
        }
    }
}
