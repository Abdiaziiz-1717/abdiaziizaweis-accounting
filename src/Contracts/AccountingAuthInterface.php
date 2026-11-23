<?php

namespace Abdiaziizaweis\Accounting\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface AccountingAuthInterface
{
    public function can(string $permission): bool;

    public function user(): Authenticatable;

    /** Current user when authenticated; null in CLI/queue with no session. */
    public function userOrNull(): ?Authenticatable;
}