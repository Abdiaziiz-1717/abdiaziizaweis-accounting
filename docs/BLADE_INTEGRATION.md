# Calling the package from your app

The API and the PHP services are the same engine. Blade apps can use either.

## Automatic: HasAccountingDocument

Put the trait on a model that implements `AccountingDocumentInterface`.

- After `saved`, the trait calls `AccountingDocumentBridge::record()`.
- On `deleting`, it calls `voidFor()` and will block the delete if void fails.

Use this when every save of that model should update the books.

## Manual: record()

Same `record()` method. Call it when there is no model event you want:

- import or queue
- a document that should post only when the user confirms
- a save that changes notes but must not touch accounting (do not use the trait)

```php
app(\Abdiaziizaweis\Accounting\Services\AccountingDocumentBridge::class)
    ->record($sale);
```

Void without deleting the host row:

```php
app(\Abdiaziizaweis\Accounting\Services\AccountingDocumentBridge::class)
    ->voidFor($sale, strict: true, reason: 'Cancelled');
```

`record()` logs failures and does not throw, so a sale save is not rolled back by an accounting error. Strict void does throw.

## Auth

Routes use your `accounting.middleware` (session `auth` is the default). No permission Gates are required. Add them in the host later if you want to hide screens by role.
