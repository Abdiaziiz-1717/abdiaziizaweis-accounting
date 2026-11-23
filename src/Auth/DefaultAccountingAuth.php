<?php

namespace Abdiaziizaweis\Accounting\Auth;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Abdiaziizaweis\Accounting\Contracts\AccountingAuthInterface;

class DefaultAccountingAuth implements AccountingAuthInterface
{
    public function can(string $permission): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        if (Gate::has($permission)) {
            return Gate::forUser($user)->check($permission);
        }

        if (method_exists($user, 'can')) {
            return $user->can($permission);
        }

        return false;
    }

    public function user(): Authenticatable
    {
        $user = auth()->user();
        if (! $user instanceof Authenticatable) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return $user;
    }
}