<?php

namespace Abdiaziizaweis\Accounting\Context;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Symfony\Component\HttpFoundation\Response;

class MultiBranchContextResolver implements AccountingContextInterface
{
    protected int $organizationId = 1;

    protected ?int $branchId = null;

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

        return $this->resolveBranchFromRequest($request);
    }

    public function getOrganizationId(): int
    {
        return $this->organizationId;
    }

    public function getBranchId(): ?int
    {
        return $this->branchId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    protected function resolveBranchFromRequest(Request $request): ?Response
    {
        $header = $request->header('X-Branch-Id');
        $branchId = $header !== null && $header !== '' ? (int) $header : null;

        if ($branchId === null || $branchId < 1) {
            return response()->json([
                'success' => false,
                'message' => 'Active branch is required. Send X-Branch-Id header.',
            ], 422);
        }

        if (! $this->canAccess($branchId)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this branch.',
                'code' => 'branch_access_denied',
            ], 403);
        }

        $this->setBranchId($branchId);

        return null;
    }

    protected function setBranchId(?int $branchId): void
    {
        $this->branchId = $branchId;

        if (class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($branchId);
        }
    }

    protected function isSuperAdmin(): bool
    {
        return $this->user !== null
            && method_exists($this->user, 'isSuperAdmin')
            && $this->user->isSuperAdmin();
    }

    /**
     * @return Collection<int, int>
     */
    protected function accessibleBranchIds(): Collection
    {
        if ($this->user === null) {
            return collect();
        }

        $branchClass = config('accounting.models.branch');
        if (! is_string($branchClass) || ! class_exists($branchClass)) {
            return collect();
        }

        if ($this->isSuperAdmin()) {
            return $branchClass::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('status', 'active')
                ->when(
                    $this->user->organization_id ?? null,
                    fn ($q) => $q->where('organization_id', $this->user->organization_id)
                )
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values();
        }

        if (! method_exists($this->user, 'branches')) {
            return collect();
        }

        return $this->user->branches()
            ->where('branches.status', 'active')
            ->whereNull('branches.deleted_at')
            ->wherePivot('status', 'active')
            ->pluck('branches.id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    protected function canAccess(?int $branchId): bool
    {
        if ($branchId === null) {
            return false;
        }

        $branchClass = config('accounting.models.branch');
        if (! is_string($branchClass) || ! class_exists($branchClass)) {
            return false;
        }

        $branch = $branchClass::withoutGlobalScopes()
            ->whereKey($branchId)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->first();

        if (! $branch) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            if ($this->user?->organization_id) {
                return (int) $branch->organization_id === (int) $this->user->organization_id;
            }

            return true;
        }

        return $this->accessibleBranchIds()->contains($branchId);
    }
}