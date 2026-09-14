<?php

use App\Exceptions\InsufficientStockException;
use App\Exceptions\WorkflowException;
use App\Http\Middleware\RequestContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [RequestContext::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->dontReport([WorkflowException::class, InsufficientStockException::class]);
        $exceptions->render(function (WorkflowException $exception, Request $request) {
            return response()->json(['message' => $exception->getMessage(), 'request_id' => $request->attributes->get('request_id')], $exception->status);
        });
        $exceptions->render(function (InsufficientStockException $exception) {
            return response()->json(['message' => 'Insufficient stock.', 'errors' => [
                'product_id' => $exception->productId, 'requested_quantity' => $exception->requestedQuantity,
                'available_quantity' => $exception->availableQuantity,
            ]], 422);
        });
        $exceptions->respond(function ($response) {
            if (request()->is('api/*') && $response->getStatusCode() >= 500) {
                return response()->json(['message' => 'The request could not be completed.', 'request_id' => request()->attributes->get('request_id')], $response->getStatusCode());
            }

            return $response;
        });
    })->create();
