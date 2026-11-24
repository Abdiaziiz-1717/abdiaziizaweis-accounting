# Example

After bootstrap, mappings are empty. Create the operation in your UI (or with HTTP), then save documents.

## Save the mapping

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

`GET /operations` then shows the label and amount sources. You do not register this in PHP.

Tax is the same idea: add lines whose `amount_source` is `tax_amount` and send `tax_amount` on the payload.

## Model

```php
use Abdiaziizaweis\Accounting\Concerns\HasAccountingDocument;
use Abdiaziizaweis\Accounting\Contracts\AccountingDocumentInterface;
use Illuminate\Database\Eloquent\Model;
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

`HasAccountingDocument` posts on save and voids on delete. See BLADE_INTEGRATION.md if you need to call `record()` yourself.
