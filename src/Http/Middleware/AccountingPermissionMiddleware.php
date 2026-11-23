<?php

namespace Abdiaziizaweis\Accounting\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Abdiaziizaweis\Accounting\Contracts\AccountingAuthInterface;
use Symfony\Component\HttpFoundation\Response;

class AccountingPermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! app(AccountingAuthInterface::class)->can($permission)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        return $next($request);
    }
}