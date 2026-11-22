<?php

namespace Jtech\Accounting;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Jtech\Accounting\Auth\DefaultAccountingAuth;
use Jtech\Accounting\Console\BootstrapCommand;
use Jtech\Accounting\Console\InstallCommand;
use Jtech\Accounting\Context\MultiBranchContextResolver;
use Jtech\Accounting\Context\MultiTenantContextResolver;
use Jtech\Accounting\Context\MultiTenantMultiBranchContextResolver;
use Jtech\Accounting\Context\SingleTenantContextResolver;
use Jtech\Accounting\Contracts\AccountingAuthInterface;
use Jtech\Accounting\Contracts\AccountingContextInterface;
use Jtech\Accounting\Http\Middleware\AccountingPermissionMiddleware;
use Jtech\Accounting\Http\Middleware\ResolveAccountingContext;
use Jtech\Accounting\Services\AccountingDocumentBridge;
use Jtech\Accounting\Services\AccountingJournalService;
use Jtech\Accounting\Services\AccountingMappingResolver;
use Jtech\Accounting\Services\AccountingReportService;
use Jtech\Accounting\Services\AccountingTransactionService;
use Jtech\Accounting\Services\AccountingTransferService;

class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/accounting.php', 'accounting');

        $this->app->bind(
            AccountingContextInterface::class,
            match ((string) config('accounting.tenancy_mode', 'multi_tenant_multi_branch')) {
                'multi_tenant' => MultiTenantContextResolver::class,
                'multi_branch' => MultiBranchContextResolver::class,
                'multi_tenant_multi_branch' => MultiTenantMultiBranchContextResolver::class,
                default => SingleTenantContextResolver::class,
            }
        );

        $this->app->bind(
            AccountingAuthInterface::class,
            (string) config('accounting.auth.guard', DefaultAccountingAuth::class)
        );

        $this->app->singleton(AccountingDocumentBridge::class);
        $this->app->singleton(AccountingTransactionService::class);
        $this->app->singleton(AccountingJournalService::class);
        $this->app->singleton(AccountingMappingResolver::class);
        $this->app->singleton(AccountingTransferService::class);
        $this->app->singleton(AccountingReportService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/accounting.php' => config_path('accounting.php'),
        ], 'accounting-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'accounting-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                BootstrapCommand::class,
            ]);
        }

        $this->registerMiddlewareAliases();
        $this->registerPermissionMiddlewareAlias();
        $this->loadRoutes();
    }

    protected function loadRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $prefix = (string) config('accounting.route_prefix', 'api/v1/accounting');
        $middleware = config('accounting.middleware', ['auth:sanctum']);

        Route::middleware($middleware)
            ->prefix($prefix)
            ->group(__DIR__.'/../routes/api.php');
    }

    protected function registerMiddlewareAliases(): void
    {
        $router = $this->app['router'];

        $router->aliasMiddleware('accounting.context', ResolveAccountingContext::class);
    }

    protected function registerPermissionMiddlewareAlias(): void
    {
        $router = $this->app['router'];
        $aliases = method_exists($router, 'getMiddleware') ? $router->getMiddleware() : [];

        if (! array_key_exists('permission', $aliases)) {
            $router->aliasMiddleware('permission', AccountingPermissionMiddleware::class);
        }
    }
}