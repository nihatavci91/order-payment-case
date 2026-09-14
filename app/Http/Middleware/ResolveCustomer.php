<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Claude Code desteğiyle yazıldı: Sanctum kaldırıldıktan sonra müşterinin X-Customer-Id header'ı ile tanımlanması.
 *
 * Authentication is out of scope for this case. The customer is identified by the
 * X-Customer-Id header so that orders stay isolated per customer and per store.
 * In production this would be replaced by a real auth layer (token, gateway, etc.).
 */
class ResolveCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header('X-Customer-Id');
        $customer = is_string($id) && ctype_digit($id) ? User::query()->find((int) $id) : null;
        if (! $customer) {
            return response()->json([
                'message' => 'A valid X-Customer-Id header is required.',
                'request_id' => $request->attributes->get('request_id'),
            ], 401);
        }
        // Controllers keep using $request->user(); only the source of the customer changes.
        $request->setUserResolver(fn () => $customer);

        return $next($request);
    }
}
