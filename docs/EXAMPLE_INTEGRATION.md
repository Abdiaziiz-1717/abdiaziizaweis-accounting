# Car sales dealership — accounting integration example

This walkthrough shows how a **car sales / service / parts** Laravel app integrates `abdiaziizaweis/accounting` using first-class modules **`sales`**, **`service`**, and **`parts`** (package enum + default bootstrap mappings).

**Assumptions:** `multi_tenant_multi_branch`, authenticated API (`auth:sanctum` or your `accounting.middleware` stack), `Organization` and `Branch` on the user, branch header `X-Branch-Id` (or your resolver equivalent).

---

## 1. AppServiceProvider (optional extra document types)

The package already registers default **sales / service / parts** invoice and payment mappings via `BuiltinDocumentRegistry`. Add host-specific types only when needed:

```php
<?php

namespace App\Providers;

use Abdiaziizaweis\Accounting\Contracts\AccountingAuthInterface;
use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Abdiaziizaweis\Accounting\Auth\DefaultAccountingAuth;
use Abdiaziizaweis\Accounting\Context\MultiTenantMultiBranchContextResolver;
use Abdiaziizaweis\Accounting\Registry\DocumentRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AccountingContextInterface::class, MultiTenantMultiBranchContextResolver::class);
        $this->app->bind(AccountingAuthInterface::class, DefaultAccountingAuth::class);
    }

    public function boot(): void
    {
        DocumentRegistry::register(
            'parts',
            'return',
            'Parts return (credit)',
            'parts_revenue',
            'accounts_receivable',
            false,
            false,
        );
    }
}
```

After deploy:

```bash
php artisan accounting:bootstrap --organization-id=1 --branch-id=1
```

---

## 2. Models

Use **`HasAccountingDocument`** — it includes **`HasDefaultDocumentMethods`** (`getPaymentMethodId()` etc. default to null).

### `SalesOrder` (vehicle sale invoice)

```php
<?php

namespace App\Models;

use Abdiaziizaweis\Accounting\Concerns\HasAccountingDocument;
use Abdiaziizaweis\Accounting\Contracts\AccountingDocumentInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class SalesOrder extends Model implements AccountingDocumentInterface
{
    use HasAccountingDocument;

    public function getDocumentId(): int { return (int) $this->id; }
    public function getDocumentType(): string { return 'sales_invoice'; }
    public function getDocumentModule(): string { return 'sales'; }
    public function getAmount(): float { return (float) $this->total; }
    public function getTaxAmount(): float { return (float) $this->tax; }
    public function getDiscountAmount(): float { return (float) $this->discount; }
    public function getDate(): Carbon { return $this->sold_at ?? $this->created_at; }
    public function getCurrency(): string { return 'USD'; }
    public function getMorphClass(): string { return self::class; }
    public function getReference(): ?string { return $this->order_number; }
    public function getDescription(): ?string
    {
        return "Vehicle sale {$this->order_number}";
    }
}
```

`inferModuleAndDocumentType()` recognizes `sales_invoice` → module `sales`, `document_type` `invoice`. Tax/discount N-line splits apply when branch tax/discount accounts are set.

### `SalesPayment`

```php
class SalesPayment extends Model implements AccountingDocumentInterface
{
    use HasAccountingDocument;

    public function getDocumentType(): string { return 'sales_payment'; }
    public function getDocumentModule(): string { return 'sales'; }
    // ... amount, date, reference, etc.

    public function getPaymentMethodId(): ?int
    {
        return $this->payment_method_id ? (int) $this->payment_method_id : null;
    }
    // getPaymentSide() defaults to 'sales' via trait
}
```

If **`getPaymentMethodId()` is null**, auto-map uses document mapping `(sales, payment)` → cash / AR (configure accounts in mapping UI).

### `ServiceOrder` / `PartsInvoice`

Same pattern with `getDocumentModule(): 'service'` or `'parts'` and types `service_invoice` / `parts_invoice`.

### `PurchasePayment` (override payment side)

```php
public function getDocumentModule(): string { return 'purchase'; }
public function getDocumentType(): string { return 'purchase_payment'; }

public function getPaymentSide(): string
{
    return 'purchase';
}
```

### `Expense` (expense category)

```php
public function getDocumentType(): string { return 'expense'; }
public function getDocumentModule(): string { return 'expenses'; }

public function getExpenseCategoryId(): ?int
{
    return $this->expense_category_id ? (int) $this->expense_category_id : null;
}
```

---

## 3. End-to-end: vehicle sale

Authenticated user with branch context; middleware from `config('accounting.middleware')`.

```php
$sale = SalesOrder::create([...]);
// HasAccountingDocument → bridge record() → type sales_invoice, module sales
// auto_map → mapping (sales, invoice) → AR / sales_revenue
```

```http
GET /api/v1/accounting/transactions?type=sales_invoice
GET /api/v1/accounting/health
GET /api/v1/accounting/transactions/unmapped
```

---

## 4. Multi-line journal entries (`getJournalLines`)

Register documents that always use custom lines:

```php
DocumentRegistry::register(
    'sales',
    'invoice',
    'Vehicle sale (COGS)',
    'accounts_receivable',
    'sales_revenue',
    hasTax: true,
    usesCustomLines: true,
);
```

### `SaleDocument` — 4 lines (AR, revenue, COGS, inventory)

```php
use Abdiaziizaweis\Accounting\ValueObjects\JournalLine;

public function getJournalLines(): ?array
{
    return [
        JournalLine::debit('accounts_receivable', $this->total),
        JournalLine::credit('sales_revenue', $this->subtotal),
        JournalLine::debit('cost_of_sales', $this->cost),
        JournalLine::credit('inventory_asset', $this->cost),
    ];
}
```

### `SaleDocument` — 5 lines (add tax)

Add `JournalLine::credit('tax_payable', $this->tax)` and increase the AR debit to `$this->total_with_tax`.

### `ServiceOrder` — 3 lines (AR, revenue, tax)

```php
return [
    JournalLine::debit('accounts_receivable', $this->total),
    JournalLine::credit('service_revenue', $this->subtotal),
    JournalLine::credit('tax_payable', $this->tax),
];
```

### `PartsInvoice` — mapping mode

```php
public function getJournalLines(): ?array
{
    return null; // simple AR / parts_revenue from mapping table
}
```

### `PayrollRun` — 5 lines

```php
return [
    JournalLine::debit('payroll_expenses', $this->gross),
    JournalLine::credit('cash_and_cash_equivalents', $this->net),
    JournalLine::credit('tax_payable', $this->withholding),
    JournalLine::credit('pension_payable', $this->pension),
    JournalLine::credit('health_insurance_payable', $this->health),
];
```

---

## Checklist

1. `composer require abdiaziizaweis/accounting`, `accounting:install`, `accounting:bootstrap`
2. Models implement `AccountingDocumentInterface` + `HasAccountingDocument`
3. Override `getPaymentMethodId`, `getExpenseCategoryId`, `getPaymentSide` only when needed
4. Use `getJournalLines()` for COGS / payroll / complex tax when mapping is not enough
5. Register `DocumentRegistry` rows; set `usesCustomLines: true` for code-managed types
6. Permissions assigned to sales/accounting roles
7. Poll `GET /health` after go-live