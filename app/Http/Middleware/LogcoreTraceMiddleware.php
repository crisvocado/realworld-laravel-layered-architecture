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
        LogcoreTraceContext::setTraceFromHeader(is_string($traceparent) ? $traceparent : null);

        $response = $next($request);

        $response->headers->set('traceparent', LogcoreTraceContext::generateTraceparentHeader());

        return $response;
    }
}
