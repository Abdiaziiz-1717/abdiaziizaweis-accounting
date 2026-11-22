<?php

namespace Jtech\Accounting\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Jtech\Accounting\Context\MultiBranchContextResolver;
use Jtech\Accounting\Context\MultiTenantContextResolver;
use Jtech\Accounting\Context\MultiTenantMultiBranchContextResolver;
use Jtech\Accounting\Context\SingleTenantContextResolver;
use Jtech\Accounting\Contracts\AccountingContextInterface;
use Symfony\Component\HttpFoundation\Response;

class ResolveAccountingContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $resolver = $this->makeResolver((string) config('accounting.tenancy_mode', 'single_tenant'));

        $failed = $resolver->resolve($request);
        if ($failed !== null) {
            return $failed;
        }

        app()->instance(AccountingContextInterface::class, $resolver);

        return $next($request);
    }

    protected function makeResolver(string $tenancyMode): SingleTenantContextResolver|MultiBranchContextResolver|MultiTenantContextResolver|MultiTenantMultiBranchContextResolver
    {
        return match ($tenancyMode) {
            'multi_branch' => new MultiBranchContextResolver,
            'multi_tenant' => new MultiTenantContextResolver,
            'multi_tenant_multi_branch' => new MultiTenantMultiBranchContextResolver,
            default => new SingleTenantContextResolver,
        };
    }
}