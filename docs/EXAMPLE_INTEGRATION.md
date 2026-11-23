# Example integration (v2)

The package does not know sales, hotels, or purchases. After `accounting:bootstrap` the chart of accounts exists and **every operation mapping is empty**. Documents stay `not_mapped` until your UI saves lines.

## 1. Register a catalog (optional, no database writes)

```php
use Abdiaziizaweis\Accounting\Registry\DocumentRegistry;

DocumentRegistry::register('sale.recorded', 'Sale recorded', ['amount', 'cost', 'tax_amount']);
DocumentRegistry::register('sale.payment', 'Sale payment', ['amount']);
```

`GET /registry/documents` returns that list so the mapping screen can show dropdowns. It does not insert mapping rows.

## 2. Host saves lines (your UI)

`PUT /api/v1/accounting/operations/sale.recorded`

```json
{
  "label": "Sale recorded",
  "is_enabled": true,
  "lines": [
    {"account_key": "accounts_receivable", "side": "debit", "amount_source": "amount"},
    {"account_key": "sales_revenue", "side": "credit", "amount_source": "amount"},
    {"account_key": "cost_of_sales", "side": "debit", "amount_source": "cost", "skip_when_zero": true},
    {"account_key": "inventory_asset", "side": "credit", "amount_source": "cost", "skip_when_zero": true}
  ]
}
```

Tax is the same mechanism, not a package splitter. Add lines whose `amount_source` is `tax_amount` or `discount_amount` and send those keys on the payload.

## 3. Document adapter

```php
use Abdiaziizaweis\Accounting\Concerns\HasAccountingDocument;
use Abdiaziizaweis\Accounting\Contracts\AccountingDocumentInterface;
use Illuminate\Support\Carbon;

class Sale extends Model implements AccountingDocumentInterface
{
    use HasAccountingDocument;

    public function getDocumentId(): int
    {
        return (int) $this->id;
    }

    public function getOperationKey(): string
    {
        return 'sale.recorded';
    }

    public function getAccountingPayload(): array
    {
        return [
            'amount' => (float) $this->total,
            'cost' => (float) $this->cost,
            'tax_amount' => (float) $this->tax,
        ];
    }

    public function getDate(): Carbon
    {
        return $this->sold_on;
    }

    public function getMorphClass(): string
    {
        return self::class;
    }
}
```

`HasAccountingDocument` calls the bridge on save. The bridge looks up `sale.recorded`. No mapping → transaction `not_mapped`, no journal. Balanced lines → journal from those lines only.

You do not need `getJournalLines()` for tax, discount, or COGS. Implement it only when a document must hard-code lines in PHP.
