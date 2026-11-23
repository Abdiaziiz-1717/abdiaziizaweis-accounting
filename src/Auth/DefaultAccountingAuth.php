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
        return Gate::allows($permission)
            || (auth()->user()?->can($permission) ?? false);
    }

    public function user(): Authenticatable
    {
        $user = auth()->user();
        if (! $user instanceof Authenticatable) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return $user;
    }

    public function userOrNull(): ?Authenticatable
    {
        $user = auth()->user();

        return $user instanceof Authenticatable ? $user : null;
    }
}