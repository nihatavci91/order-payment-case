<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $candidate = $request->header('X-Request-ID');
        $requestId = is_string($candidate) && Str::isUuid($candidate) ? $candidate : (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);
        Log::shareContext(['request_id' => $requestId]);
        $start = hrtime(true);
        try {
            if (app()->environment('production') && ! $request->isSecure()) {
                abort(426, 'HTTPS is required.');
            }
            $response = $next($request);
        } catch (Throwable $exception) {
            $response = app(ExceptionHandler::class)->render($request, $exception);
            app(ExceptionHandler::class)->report($exception);
        }
        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('Cache-Control', 'no-store');
        Log::channel('workflow')->info('http.completed', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'route' => $request->route()?->uri(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round((hrtime(true) - $start) / 1_000_000, 2),
        ]);
        Log::withoutContext(['request_id']);
        Log::flushSharedContext();

        return $response;
    }
}
