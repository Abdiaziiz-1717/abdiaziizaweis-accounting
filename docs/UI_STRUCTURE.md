# Screens to build

The package does not ship Blade or React. Build these pages against `api/v1/accounting` (or your `route_prefix`). A hotel accounting UI can be a visual reference only. Do not copy its tax splits or seeded maps.

Suggested menu:

- Chart of accounts
- Transactions, including an unmapped filter
- Journal entries, including a manual balanced entry
- Transfers
- Mapping setup
- Settings (fiscal year, method, close books)
- Reports: trial balance, profit and loss, balance sheet, cash flow, account statement, journal activity by month

## Mapping setup

This is the screen that turns documents into journals. Bootstrap leaves it empty.

- `GET /operations` lists what you already saved (key, label, lines, amount sources).
- `PUT /operations/{operationKey}` creates or replaces one operation. The key can contain dots (`sale.recorded`).
- `GET /accounts` fills the account dropdown.

Each line: account, debit or credit, amount source (`amount`, `cost`, `tax_amount`, `discount_amount`, or a name you invent), skip when zero.

The API refuses to enable a map with fewer than two lines, or when an amount source does not have the same number of debit and credit lines.

There is no separate registry and no tax-account settings page. Tax and discount are extra lines.

## Transactions

- List: `GET /transactions`
- Unmapped: `GET /transactions/unmapped`
- Detail: `GET /transactions/{id}`
- Clear a journal: `POST /transactions/{id}/unmap`
- Retry posting after you fixed a mapping: `POST /transactions/bulk-map`

Until you enable a mapping, health stays `ok` even if transactions are unmapped.

## Auth

The user must be logged in. The package does not require permission strings. If you add roles later, wrap the routes or the pages yourself.
