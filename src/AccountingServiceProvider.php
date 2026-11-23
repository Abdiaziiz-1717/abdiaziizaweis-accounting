<?php

namespace Abdiaziizaweis\Accounting;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Abdiaziizaweis\Accounting\Auth\DefaultAccountingAuth;
use Abdiaziizaweis\Accounting\Console\BootstrapCommand;
use Abdiaziizaweis\Accounting\Console\InstallCommand;
use Abdiaziizaweis\Accounting\Context\MultiBranchContextResolver;
use Abdiaziizaweis\Accounting\Context\MultiTenantContextResolver;
use Abdiaziizaweis\Accounting\Context\MultiTenantMultiBranchContextResolver;
use Abdiaziizaweis\Accounting\Context\SingleTenantContextResolver;
use Abdiaziizaweis\Accounting\Contracts\AccountingAuthInterface;
use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Abdiaziizaweis\Accounting\Http\Middleware\AccountingPermissionMiddleware;
use Abdiaziizaweis\Accounting\Http\Middleware\ResolveAccountingContext;
use Abdiaziizaweis\Accounting\Services\AccountingDocumentBridge;
use Abdiaziizaweis\Accounting\Services\AccountingJournalService;
use Abdiaziizaweis\Accounting\Services\AccountingMappingResolver;
use Abdiaziizaweis\Accounting\Services\AccountingPeriodGuard;
use Abdiaziizaweis\Accounting\Services\AccountingReportService;
use Abdiaziizaweis\Accounting\Services\AccountingTransactionService;
use Abdiaziizaweis\Accounting\Services\AccountingTransferService;

/**
 * Laravel package entry point for abdiaziizaweis/accounting.
 *
 * **register()**
 * - Merges `config/accounting.php`
 * - Binds {@see AccountingContextInterface} from `tenancy_mode`
 * - Binds {@see AccountingAuthInterface} from `auth.guard`
 * - Registers singletons: bridge, transaction/journal/mapping/report/transfer services
 *
 * **boot()**
 * - Loads migrations and API routes (`route_prefix`, `middleware`)
 * - Publishes `accounting-config` and `accounting-migrations`
 * - Registers `accounting:install` and `accounting:bootstrap`
 * - Aliases middleware: `accounting.context`, `permission` (when not already defined)
 */
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
        $this->app->singleton(AccountingPeriodGuard::class);
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