<?php

namespace Abdiaziizaweis\Accounting\Context;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Symfony\Component\HttpFoundation\Response;

class MultiTenantContextResolver implements AccountingContextInterface
{
    protected int $organizationId = 0;

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

        $organizationId = $user->organization_id ?? null;
        if ($organizationId === null || (int) $organizationId < 1) {
            return response()->json([
                'success' => false,
                'message' => 'Organization context is required.',
            ], 422);
        }

        $this->user = $user;
        $this->userId = (int) $user->getAuthIdentifier();
        $this->organizationId = (int) $organizationId;

        return null;
    }

    public function getOrganizationId(): int
    {
        return $this->organizationId;
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