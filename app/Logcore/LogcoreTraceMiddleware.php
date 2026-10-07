<?php declare(strict_types=1);

namespace App\Logcore;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogcoreTraceMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $traceparent = $request->header('traceparent');
        if (is_string($traceparent)) {
            $parsed = LogcoreTraceContext::parseTraceparent($traceparent);
            if ($parsed !== null) {
                LogcoreTraceContext::set($parsed);
            }
        }

        /** @var Response $response */
        $response = $next($request);

        $currentTrace = LogcoreTraceContext::get();
        if ($currentTrace !== null && !$response->headers->has('traceparent')) {
            $flags = (!empty($currentTrace['sampled'])) ? '01' : '00';
            $spanId = $currentTrace['spanId'] ?? '0000000000000000';
            $response->headers->set('traceparent', sprintf('00-%s-%s-%s', $currentTrace['traceId'], $spanId, $flags));
        }

        return $response;
    }
}
