<?php

namespace Abdiaziizaweis\Accounting\Context;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Symfony\Component\HttpFoundation\Response;

class SingleTenantContextResolver implements AccountingContextInterface
{
    protected int $userId = 0;

    protected ?Authenticatable $user = null;

    public function resolve(Request $request): ?Response
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $this->user = $user;
        $this->userId = (int) $user->getAuthIdentifier();

        return null;
    }

    public function getOrganizationId(): int
    {
        return 1;
    }

    public function getBranchId(): ?int
    {
        return null;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }
}