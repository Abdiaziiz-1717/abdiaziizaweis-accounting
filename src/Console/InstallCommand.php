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
            $taxSplitting,
            $discountSplitting,
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
            $taxSplitting,
            $discountSplitting,
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
        $this->line("    module:        'sales',");
        $this->line("    documentType:  'sales_invoice',");
        $this->line("    label:         'Sales Invoice',");
        $this->line("    defaultDebit:  'accounts_receivable',");
        $this->line("    defaultCredit: 'sales_revenue',");
        $this->line('    hasTax:        true,');
        $this->line('    hasDiscount:   true,');
        $this->line(');');
        $this->newLine();
        $this->line('Then run: php artisan accounting:bootstrap');

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
     * @return array{0: string, 1: string, 2: int, 3: bool, 4: bool, 5: string, 6: array<int, string>, 7: ?int, 8: ?int}
     */
    protected function gatherAnswers(): array
    {
        $tenancyMode = $this->choice(
            'Tenancy mode',
            array_keys($this->resolverMap),
            'multi_tenant_multi_branch'
        );

        $currency = (string) $this->ask('Default currency', 'USD');

        $fyStart = (int) $this->ask('Fiscal year start month (1-12)', '1');
        $fyStart = max(1, min(12, $fyStart));

        $taxSplitting = (bool) $this->confirm('Enable tax splitting?', true);
        $discountSplitting = (bool) $this->confirm('Enable discount splitting?', true);

        $routePrefix = (string) $this->ask('Route prefix', 'api/v1/accounting');

        $middlewareInput = (string) $this->ask('Middleware (comma-separated)', 'auth:sanctum,accounting.context');
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
            $taxSplitting,
            $discountSplitting,
            $routePrefix,
            $middlewareList,
            $organizationId,
            $branchId,
        ];
    }

    /**
     * @param  array<int, string>  $middlewareList
     */
    protected function buildConfigFromStub(
        string $stub,
        string $tenancyMode,
        string $currency,
        int $fyStart,
        bool $taxSplitting,
        bool $discountSplitting,
        string $routePrefix,
        array $middlewareList,
    ): string {
        $middlewareList = $middlewareList !== [] ? $middlewareList : ['auth:sanctum', 'accounting.context'];
        $middlewarePhp = implode(', ', array_map(
            fn (string $m) => "'".addslashes($m)."'",
            $middlewareList
        ));

        $replacements = [
            '{{TENANCY_MODE}}' => $tenancyMode,
            '{{CURRENCY}}' => addslashes($currency),
            '{{FY_START}}' => (string) $fyStart,
            '{{TAX_SPLITTING}}' => $taxSplitting ? 'true' : 'false',
            '{{DISCOUNT_SPLITTING}}' => $discountSplitting ? 'true' : 'false',
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