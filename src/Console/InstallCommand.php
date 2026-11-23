<?php

namespace Abdiaziizaweis\Accounting\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Abdiaziizaweis\Accounting\Context\MultiBranchContextResolver;
use Abdiaziizaweis\Accounting\Context\MultiTenantContextResolver;
use Abdiaziizaweis\Accounting\Context\MultiTenantMultiBranchContextResolver;
use Abdiaziizaweis\Accounting\Context\SingleTenantContextResolver;
class InstallCommand extends Command
{
    protected $signature = 'accounting:install';

    protected $description = 'Install the Abdiaziizaweis Accounting package (config, bindings, migrations, bootstrap)';

  /** @var array<string, class-string> */
    protected array $resolverMap = [
        'single_tenant' => SingleTenantContextResolver::class,
        'multi_tenant' => MultiTenantContextResolver::class,
        'multi_branch' => MultiBranchContextResolver::class,
        'multi_tenant_multi_branch' => MultiTenantMultiBranchContextResolver::class,
    ];

    public function handle(): int
    {
        $this->showBanner();

        [
            $tenancyMode,
            $currency,
            $fyStart,
            $routePrefix,
            $middlewareList,
            $organizationId,
            $branchId,
        ] = $this->gatherAnswers();

        $configPath = config_path('accounting.php');
        $stubPath = dirname(__DIR__, 2).'/stubs/accounting.php.stub';

        if (! File::exists($stubPath)) {
            $this->error("Config stub not found at {$stubPath}.");

            return self::FAILURE;
        }

        $configContents = $this->buildConfigFromStub(
            File::get($stubPath),
            $tenancyMode,
            $currency,
            $fyStart,
            $routePrefix,
            $middlewareList,
        );

        File::ensureDirectoryExists(dirname($configPath));
        File::put($configPath, $configContents);
        $this->components->info('✔ Config written');

        $this->components->info('✔ Context resolver: '.$this->resolverMap[$tenancyMode].' (via accounting.tenancy_mode)');

        $this->callSilent('config:clear');

        $migrateExit = $this->call('migrate', ['--force' => true]);
        if ($migrateExit !== self::SUCCESS) {
            $this->error('Migrations failed or were skipped.');

            return $migrateExit;
        }
        $this->components->info('✔ Migrations ran');
        $this->line('  MySQL/MariaDB: use package 1.0.2+ for FK-safe migrations; set tenancy_mode before first migrate.');

        $bootstrapArgs = ['--force' => false];
        if ($organizationId !== null) {
            $bootstrapArgs['--organization-id'] = $organizationId;
        }
        if ($branchId !== null) {
            $bootstrapArgs['--branch-id'] = $branchId;
        }

        $bootstrapExit = $this->call('accounting:bootstrap', $bootstrapArgs);
        if ($bootstrapExit === self::SUCCESS) {
            $this->components->info('✔ Chart of accounts seeded');
            $this->components->info('✔ Default mappings seeded');
        } else {
            $this->warn('Bootstrap did not complete. Run: php artisan accounting:bootstrap --organization-id=… --branch-id=…');
        }

        $this->printManualBindingInstructions($this->resolverMap[$tenancyMode]);

        $this->newLine();
        $this->line('Register your document types in AppServiceProvider:');
        $this->newLine();
        $this->line('use Abdiaziizaweis\Accounting\Registry\DocumentRegistry;');
        $this->newLine();
        $this->line('DocumentRegistry::register(');
        $this->line("    operationKey: 'sale.recorded',");
        $this->line("    label: 'Sale recorded',");
        $this->line("    amountSources: ['amount', 'cost'],");
        $this->line(');');
        $this->line('// Then save mapping lines in your UI (PUT /operations/sale.recorded). Bootstrap does not seed them.');
        $this->newLine();
        $this->line('Then run: php artisan accounting:bootstrap');
        $this->newLine();
        $this->line(
            'Set accounting.middleware in config/accounting.php to match your app\'s auth middleware (auth, auth:sanctum, auth:api, etc.).'
        );

        return self::SUCCESS;
    }

    protected function showBanner(): void
    {
        $this->newLine();
        $this->line('  ┌──────────────────────────────────────────┐');
        $this->line('  │   Abdiaziiz aweis Accounting Installer   │');
        $this->line('  └──────────────────────────────────────────┘');
        $this->newLine();
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: string, 4: array<int, string>, 5: ?int, 6: ?int}
     */
    protected function gatherAnswers(): array
    {
        $tenancyMode = $this->choice(
            'Tenancy mode',
            array_keys($this->resolverMap),
            'single_tenant'
        );

        $this->printTenancyModeGuidance($tenancyMode);

        $currency = (string) $this->ask('Default currency', 'USD');

        $fyStart = (int) $this->ask('Fiscal year start month (1-12)', '1');
        $fyStart = max(1, min(12, $fyStart));

        $routePrefix = (string) $this->ask('Route prefix', 'api/v1/accounting');

        $middlewareInput = (string) $this->ask('Middleware (comma-separated)', 'auth,accounting.context');
        $middlewareList = array_values(array_filter(array_map('trim', explode(',', $middlewareInput))));

        $organizationId = null;
        $branchId = null;

        if (in_array($tenancyMode, ['multi_tenant', 'multi_tenant_multi_branch'], true)) {
            $organizationId = (int) $this->ask('Organization ID for initial bootstrap', '1');
        }

        if (in_array($tenancyMode, ['multi_branch', 'multi_tenant_multi_branch'], true)) {
            $branchId = (int) $this->ask('Branch ID for initial bootstrap', '1');
        }

        return [
            $tenancyMode,
            $currency,
            $fyStart,
            $routePrefix,
            $middlewareList,
            $organizationId,
            $branchId,
        ];
    }

    protected function printTenancyModeGuidance(string $tenancyMode): void
    {
        $this->newLine();

        match ($tenancyMode) {
            'single_tenant' => $this->line(
                'Single tenant mode selected.'."\n".
                'This mode requires NO organizations or branches tables.'."\n".
                'Your accounting tables will have no tenant scoping.'."\n".
                'Perfect for single-store or single-company applications.'
            ),
            'multi_tenant' => $this->line('Requires an organizations table with an id column.'),
            'multi_branch' => $this->line('Requires a branches table with id and organization_id columns.'),
            'multi_tenant_multi_branch' => $this->line('Requires both organizations and branches tables.'),
            default => null,
        };

        $this->newLine();
    }

    /**
     * @param  array<int, string>  $middlewareList
     */
    protected function buildConfigFromStub(
        string $stub,
        string $tenancyMode,
        string $currency,
        int $fyStart,
        string $routePrefix,
        array $middlewareList,
    ): string {
        $middlewareList = $middlewareList !== [] ? $middlewareList : ['auth', 'accounting.context'];
        $middlewarePhp = implode(', ', array_map(
            fn (string $m) => "'".addslashes($m)."'",
            $middlewareList
        ));

        $replacements = [
            '{{TENANCY_MODE}}' => $tenancyMode,
            '{{CURRENCY}}' => addslashes($currency),
            '{{FY_START}}' => (string) $fyStart,
            '{{ROUTE_PREFIX}}' => addslashes($routePrefix),
            '{{MIDDLEWARE}}' => $middlewarePhp,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $stub);
    }

    protected function printManualBindingInstructions(string $resolverClass): void
    {
        $resolverFqn = '\\'.$resolverClass;

        $this->newLine();
        $this->line('Add this to your AppServiceProvider register() method:');
        $this->newLine();
        $this->line('$this->app->bind(');
        $this->line('    \\Abdiaziizaweis\\Accounting\\Contracts\\AccountingContextInterface::class,');
        $this->line("    {$resolverFqn}::class");
        $this->line(');');
        $this->newLine();
        $this->line('$this->app->bind(');
        $this->line('    \\Abdiaziizaweis\\Accounting\\Contracts\\AccountingAuthInterface::class,');
        $this->line('    \\Abdiaziizaweis\\Accounting\\Auth\\DefaultAccountingAuth::class');
        $this->line(');');
    }

}