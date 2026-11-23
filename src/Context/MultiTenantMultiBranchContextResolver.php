<?php

namespace Abdiaziizaweis\Accounting\Context;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MultiTenantMultiBranchContextResolver extends MultiBranchContextResolver
{
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

        return $this->resolveBranchFromRequest($request);
    }
}