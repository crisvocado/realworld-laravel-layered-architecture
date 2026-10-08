<?php declare(strict_types=1);

namespace App\Http\Middleware;

use App\Logging\LogcoreTraceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogcoreTraceMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $traceparent = $request->header('traceparent');
        if (is_string($traceparent)) {
            $trace = LogcoreTraceContext::fromTraceparent($traceparent);
            if ($trace !== null) {
                LogcoreTraceContext::set($trace);
            }
        }

        return $next($request);
    }

    /**
     * Handle tasks after the response has been sent to the browser.
     */
    public function terminate(Request $request, Response $response): void
    {
        LogcoreTraceContext::reset();
    }
}
