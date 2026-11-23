# Car sales dealership — accounting integration example

This walkthrough shows how a **car sales / service / parts** Laravel app integrates `abdiaziizaweis/accounting` with six custom `document_type` keys.

We use module **`inventory`** below because `AccountingDocumentMapping` stores `module` as the `AccountingModule` enum and the upsert API validates that enum. For a first-class `automotive` module name, add `case Automotive = 'automotive';` to `Abdiaziizaweis\Accounting\Enums\AccountingModule` (or fork the package) and replace `inventory` with `automotive` everywhere in this file.

**Assumptions:** `multi_tenant_multi_branch`, authenticated API (`auth:sanctum` or your `accounting.middleware` stack), `Organization` and `Branch` on the user, branch header `X-Branch-Id` (or your resolver equivalent).

---

## 1. AppServiceProvider

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
        // Optional — package already binds these from config('accounting.tenancy_mode')
        $this->app->bind(AccountingContextInterface::class, MultiTenantMultiBranchContextResolver::class);
        $this->app->bind(AccountingAuthInterface::class, DefaultAccountingAuth::class);
    }

    public function boot(): void
    {
        DocumentRegistry::register(
            'inventory',
            'sales_invoice',
            'Vehicle Sales Invoice',
            'accounts_receivable',
            'sales',
            true,
            true,
        );

        DocumentRegistry::register(
            'inventory',
            'sales_payment',
            'Vehicle Sale Payment',
            'bank',
            'accounts_receivable',
            false,
            false,
        );

        DocumentRegistry::register(
            'inventory',
            'service_invoice',
            'Service Department Invoice',
            'accounts_receivable',
            'sales', // or a dedicated revenue account you add to CoA
            true,
            false,
        );

        DocumentRegistry::register(
            'inventory',
            'service_payment',
            'Service Payment',
            'bank',
            'accounts_receivable',
            false,
            false,
        );

        DocumentRegistry::register(
            'inventory',
            'parts_invoice',
            'Parts Counter Invoice',
            'accounts_receivable',
            'sales',
            true,
            true,
        );

        DocumentRegistry::register(
            'inventory',
            'parts_return',
            'Parts Return (credit)',
            'sales',
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

    protected $casts = [
        'sold_at' => 'datetime',
        'subtotal' => 'float',
        'tax' => 'float',
        'discount' => 'float',
        'total' => 'float',
    ];

  public function getDocumentId(): int { return (int) $this->id; }
    public function getDocumentType(): string { return 'sales_invoice'; }
    public function getDocumentModule(): string { return 'inventory'; }
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

> **Note:** N-line tax/discount **splitting** in the bridge applies to built-in types like `hotel_invoice`. Custom `sales_invoice` posts a **two-line** JE (AR ↔ Sales) unless you customize mapping or call `mapWithLines` yourself. Tax/discount fields are still stored on the transaction for reporting/UI.

### `SalesPayment`

```php
<?php

namespace App\Models;

use Abdiaziizaweis\Accounting\Concerns\HasAccountingDocument;
use Abdiaziizaweis\Accounting\Contracts\AccountingDocumentInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class SalesPayment extends Model implements AccountingDocumentInterface
{
    use HasAccountingDocument;

    public function getDocumentId(): int { return (int) $this->id; }
    public function getDocumentType(): string { return 'sales_payment'; }
    public function getDocumentModule(): string { return 'inventory'; }
    public function getAmount(): float { return (float) $this->amount; }
    public function getTaxAmount(): float { return 0.0; }
    public function getDiscountAmount(): float { return 0.0; }
    public function getDate(): Carbon { return $this->paid_at; }
    public function getCurrency(): string { return 'USD'; }
    public function getMorphClass(): string { return self::class; }
    public function getReference(): ?string { return $this->receipt_no; }
    public function getDescription(): ?string { return "Payment for sale {$this->sales_order_id}"; }

    public function getPaymentMethodId(): ?int
    {
        return $this->payment_method_id ? (int) $this->payment_method_id : null;
    }
}
```

### `ServiceOrder` (service invoice — same pattern)

```php
<?php

namespace App\Models;

use Abdiaziizaweis\Accounting\Concerns\HasAccountingDocument;
use Abdiaziizaweis\Accounting\Contracts\AccountingDocumentInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ServiceOrder extends Model implements AccountingDocumentInterface
{
    use HasAccountingDocument;

    public function getDocumentId(): int { return (int) $this->id; }
    public function getDocumentType(): string { return 'service_invoice'; }
    public function getDocumentModule(): string { return 'inventory'; }
    public function getAmount(): float { return (float) $this->total; }
    public function getTaxAmount(): float { return (float) $this->tax; }
    public function getDiscountAmount(): float { return 0.0; }
    public function getDate(): Carbon { return $this->completed_at; }
    public function getCurrency(): string { return 'USD'; }
    public function getMorphClass(): string { return self::class; }
    public function getReference(): ?string { return $this->ro_number; }
    public function getDescription(): ?string { return "Service RO {$this->ro_number}"; }
}
```

Implement `ServicePayment`, `PartsInvoice`, and `PartsReturn` similarly with `service_payment`, `parts_invoice`, and `parts_return` types (parts return: amount = credit memo total; registry debits `sales` and credits AR to reverse revenue).

---

## 2b. Auto-map hint for custom `document_type` keys

`AccountingDocumentBridge` only sets `document_type` for built-in types in `inferModuleAndDocumentType()`. For registry keys like `sales_invoice`, bind a thin subclass so `tryAutoMap` receives the correct pair:

```php
<?php

namespace App\Services;

use Abdiaziizaweis\Accounting\Contracts\AccountingDocumentInterface;
use Abdiaziizaweis\Accounting\Services\AccountingDocumentBridge;

class DealershipAccountingBridge extends AccountingDocumentBridge
{
    private const DOC_TYPE_BY_TX = [
        'sales_invoice' => 'sales_invoice',
        'sales_payment' => 'sales_payment',
        'service_invoice' => 'service_invoice',
        'service_payment' => 'service_payment',
        'parts_invoice' => 'parts_invoice',
        'parts_return' => 'parts_return',
    ];

    public function record(AccountingDocumentInterface $document): void
    {
        $txType = $document->getDocumentType();
        if (isset(self::DOC_TYPE_BY_TX[$txType])) {
            // Replicate parent flow but force document_type — simplest host approach:
            // call parent after temporarily using a wrapper model, OR override record()
            // to pass document_type into AccountingTransactionService::recordTransaction attributes.
        }
        parent::record($document);
    }
}
```

**Pragmatic alternative:** leave the stock bridge and run **POST** `/api/v1/accounting/transactions/bulk-map` with:

```json
{
  "ids": [101],
  "module": "inventory",
  "document_type": "sales_invoice"
}
```

after each sale, or map once from the admin UI.

Register the subclass in `AppServiceProvider::register()`:

```php
$this->app->singleton(AccountingDocumentBridge::class, DealershipAccountingBridge::class);
```

*(Fully implement `record()` by copying the parent method and adding `'document_type' => self::DOC_TYPE_BY_TX[$type]` to the `$attrs` array passed to `recordTransaction`.)*

---

## 3. End-to-end: vehicle sale

**Context:** Authenticated user with `organization_id`, request includes `X-Branch-Id: 1`, middleware per `config('accounting.middleware')` (e.g. `auth` + `accounting.context`).

```php
// Controller excerpt
$sale = SalesOrder::create([
    'order_number' => 'VS-10042',
    'subtotal' => 28500,
    'tax' => 2280,
    'discount' => 500,
    'total' => 30280,
    'sold_at' => now(),
]);
// HasAccountingDocument → bridge record() → accounting_transactions row type sales_invoice
// auto_map true → maps module inventory + document_type sales_invoice (from getDocumentModule() on attrs)
```

Mapping lookup uses `getDocumentModule()` plus the `document_type` segment from `DocumentRegistry` (e.g. `sales_invoice`). Ensure bootstrap created `(inventory, sales_invoice)` mapping.

**Verify:**

```http
GET /api/v1/accounting/transactions?type=sales_invoice
Authorization: Bearer {token}
X-Branch-Id: 1
```

Expect `status: mapped` when mapping exists and accounts are valid.

---

## 4. End-to-end: customer payment

```php
SalesPayment::create([
    'sales_order_id' => $sale->id,
    'amount' => 15000,
    'payment_method_id' => 2, // e.g. "Bank transfer"
    'paid_at' => now(),
    'receipt_no' => 'RCP-9001',
]);
```

Bridge passes `payment_method_id` → `tryAutoMap` may use **payment method mapping** (DR bank / CR AR) overriding plain document map.

---

## 5. End-to-end: delete / void

```php
$sale->delete();
// deleting event → voidFor(strict: true)
// Voids accounting_transactions for this morph + sales_invoice and voids linked JEs
```

Host row is removed; accounting history remains **voided** for audit (`include_voided=1` on API).

**Manual void without delete:**

```php
app(\Abdiaziizaweis\Accounting\Services\AccountingDocumentBridge::class)
    ->voidFor($sale, false, 'Order cancelled');
```

---

## 6. Economic edit (price change)

```php
$sale->update(['total' => 29800, 'tax' => 2240]);
// saved → record() detects amount change → voids old tx, creates new not_mapped/mapped tx
```

---

## 7. Report queries

All require permission `view accounting reports` and resolved branch context.

**Month P&amp;L (service + vehicle revenue in `sales` account):**

```http
GET /api/v1/accounting/reports/profit-and-loss?date_from=2026-11-01&date_to=2026-11-30
```

**AR balance drill-down:**

```http
GET /api/v1/accounting/accounts?search=accounts_receivable
```

Then:

```http
GET /api/v1/accounting/reports/account-statement?account_id={ar_id}&date_from=2026-11-01&date_to=2026-11-30
```

**Trial balance after postings:**

```http
GET /api/v1/accounting/reports/trial-balance?as_of=2026-11-30
```

Response `data.totals.debit` should equal `data.totals.credit` when books are balanced.

---

## 8. Mapping UI via registry

Drive a front-end mapping screen from registered types + current DB mappings:

```http
GET /api/v1/accounting/registry/documents
Authorization: Bearer {token}
X-Branch-Id: 1
```

Example response shape:

```json
{
  "success": true,
  "data": {
    "modules": [
      {
        "module": "inventory",
        "documents": [
          {
            "module": "inventory",
            "document_type": "sales_invoice",
            "label": "Vehicle Sales Invoice",
            "default_debit": "accounts_receivable",
            "default_credit": "sales",
            "has_tax": true,
            "has_discount": true,
            "is_mapped": true,
            "debit_account_id": 12,
            "credit_account_id": 45,
            "is_enabled": true
          }
        ]
      }
    ]
  }
}
```

**Save admin overrides:**

```http
POST /api/v1/accounting/document-mappings/upsert
Content-Type: application/json

{
  "mappings": [
    {
      "module": "inventory",
      "document_type": "sales_invoice",
      "debit_account_id": 12,
      "credit_account_id": 45,
      "is_enabled": true
    }
  ]
}
```

`module` must be a valid `AccountingModule` enum case (here: `inventory`).

---

## 9. Payment method mapping (dealership floor plan / card)

After bootstrap, map each `PaymentMethod` row:

```http
POST /api/v1/accounting/payment-method-mappings/upsert
```

Body includes `payment_method_id`, `sales_debit_account_id` (bank/cash), `sales_credit_account_id` (AR), `is_enabled: true`.

---

## 10. Checklist

1. `composer require abdiaziizaweis/accounting`
2. `accounting:install` or migrate + bootstrap
3. `DocumentRegistry::register` all six types in `AppServiceProvider::boot()`
4. Models implement `AccountingDocumentInterface` + `HasAccountingDocument`
5. Permissions assigned to sales/accounting roles
6. GET `registry/documents` to build mapping UI
7. POST payments with `payment_method_id` for correct cash account
8. Use reports API for month-end close

This completes the car sales integration pattern for `Abdiaziizaweis\Accounting`.