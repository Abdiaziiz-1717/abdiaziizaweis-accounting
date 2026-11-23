# abdiaziizaweis/accounting

Reusable double-entry accounting module for Laravel 11 applications. Includes chart of accounts, journal entries, transaction mapping, transfers, REST APIs, and financial reports.

## Installation

```bash
composer require abdiaziizaweis/accounting
php artisan accounting:install
```

The installer writes `config/accounting.php`, runs migrations, and seeds the default chart of accounts and document mappings for your organization/branch.

Publish or customize config:

```bash
php artisan vendor:publish --tag=accounting-config
```

Re-seed CoA and mappings anytime:

```bash
php artisan accounting:bootstrap
```

## Register your document types

In your `AppServiceProvider` `boot()`:

```php
use Abdiaziizaweis\Accounting\Registry\DocumentRegistry;

DocumentRegistry::register(
    module:        'sales',
    documentType:  'sales_invoice',
    label:         'Sales Invoice',
    defaultDebit:  'accounts_receivable',
    defaultCredit: 'sales_revenue',
    hasTax:        true,
    hasDiscount:   true,
);
```

Then run `php artisan accounting:bootstrap` so default mappings are created from the registry.

## Post a document to the ledger

In your controller or service after saving your model:

```php
use Illuminate\Support\Carbon;
use Abdiaziizaweis\Accounting\Contracts\AccountingDocumentInterface;
use Abdiaziizaweis\Accounting\Services\AccountingDocumentBridge;

class SalesOrderAccountingDocument implements AccountingDocumentInterface
{
    public function __construct(private SalesOrder $order) {}

    public function getDocumentId(): int       { return $this->order->id; }
    public function getDocumentType(): string   { return 'sales_invoice'; }
    public function getDocumentModule(): string { return 'sales'; }
    public function getAmount(): float          { return $this->order->total; }
    public function getTaxAmount(): float       { return $this->order->tax; }
    public function getDiscountAmount(): float  { return $this->order->discount; }
    public function getDate(): Carbon           { return $this->order->date; }
    public function getCurrency(): string       { return 'USD'; }
    public function getMorphClass(): string     { return SalesOrder::class; }
    public function getReference(): ?string     { return $this->order->number; }
    public function getDescription(): ?string   { return null; }
}

app(AccountingDocumentBridge::class)
    ->record(new SalesOrderAccountingDocument($order));
```

## Tenancy modes

| Mode | Description |
| --- | --- |
| `single_tenant` | One org, one branch |
| `multi_tenant` | Many orgs, one branch each |
| `multi_branch` | One org, many branches |
| `multi_tenant_multi_branch` | Many orgs, many branches (default) |

Set `accounting.tenancy_mode` in config. The package binds the matching `AccountingContextInterface` resolver automatically; override in `AppServiceProvider` if needed.

## Configuration options

| Key | Default | Description |
| --- | --- | --- |
| `tenancy_mode` | `multi_tenant_multi_branch` | How organization and branch context are resolved (`single_tenant`, `multi_tenant`, `multi_branch`, `multi_tenant_multi_branch`). |
| `currency` | `USD` | Default reporting currency code for host integrations. |
| `fy_start` | `1` | First month of the fiscal year (1–12). Applied when seeding branch settings. |
| `route_prefix` | `api/v1/accounting` | URL prefix for package API routes. |
| `middleware` | `auth:sanctum`, `ResolveAccountingContext` | Middleware stack for accounting API routes. Use the `accounting.context` alias instead of the class if you prefer. |
| `features.tax_splitting` | `true` | When true, CoA bootstrap wires tax payable/receivable accounts on branch settings for invoice splits. |
| `features.discount_splitting` | `true` | When true, CoA bootstrap wires sales/purchase discount accounts for invoice splits. |
| `auth.guard` | `DefaultAccountingAuth::class` | Class bound to `AccountingAuthInterface` for API permission checks (`permission:*` middleware). |
| `models.user` | `App\Models\User::class` | Host user model for relations and activity ownership. |
| `models.organization` | `App\Models\Organization::class` | Organization model for tenancy and SaaS map rules. |
| `models.branch` | `App\Models\Branch::class` | Branch model for branch-scoped data and context. |
| `models.payment_method` | `App\Models\PaymentMethod::class` | Used when seeding payment-method mappings. |
| `models.expense_category` | `App\Models\ExpenseCategory::class` | Used when seeding expense-category mappings. |
| `tables.organizations` | `organizations` | Table name for organization foreign keys in migrations. |
| `tables.branches` | `branches` | Table name for branch foreign keys in migrations. |
| `tables.users` | `users` | Table name for user foreign keys in migrations. |
| `tables.payment_methods` | `payment_methods` | Table name for payment method FKs in migrations. |
| `tables.expense_categories` | `expense_categories` | Table name for expense category FKs in migrations. |

## Artisan commands

| Command | Purpose |
| --- | --- |
| `accounting:install` | Interactive setup: config, migrations, initial bootstrap. |
| `accounting:bootstrap` | Idempotent CoA + mapping seed for current (or CLI) org/branch context. |

## API

REST endpoints are registered under `route_prefix` with the configured `middleware`. See `routes/api.php` in the package for accounts, journal entries, transactions, mappings, transfers, reports, and document registry.

## License

MIT. See [LICENSE](LICENSE) if present in your distribution.