# Accounting Module — UI Structure Reference

## Overview

This document describes the recommended UI structure for the accounting module. It is framework-agnostic — you can implement it in React, Vue, Angular, Blade, or any other UI technology.

The structure is based on a proven production implementation (MAAMUUS / Hotel–Restaurant accounting UI: chart of accounts, transactions, journal entries, transfers, mapping setup, settings, and six financial reports).

Every page communicates with the package HTTP API documented in [KNOWLEDGE.md](KNOWLEDGE.md).

**API base path:** `/{route_prefix}` — default `api/v1/accounting` (`config('accounting.route_prefix')`). All paths below are relative to that prefix unless noted.

**Production UI patterns (MAAMUUS):**

- List pages use a shared shell: title, search, filters, export, refresh, primary action.
- Create/edit for accounts, journal entries, and transfers often live in **modals** on the list page (separate routes are optional).
- Transaction **detail** is a side drawer/offcanvas, not a full page.
- Journal entries support **Journal** (voucher cards with lines) and **List** (compact table) views on one route.
- Reports share a tabbed shell with date range, stats cards, export, and a financial statement table.

---

## 1. Navigation Structure

Suggested sidebar / app menu (permission names in parentheses):

```
Accounting
├── Chart of Accounts                    [view accounting accounts]
├── Transactions                         [view accounting transactions]
│   └── (filter: Not mapped ≈ “Unmapped”) 
├── Journal Entries                      [view accounting journal entries]
│   └── Manual entry (modal on same page)
├── Transfers                            [view accounting transfers]
├── Mapping setup                        [view accounting mappings]
├── Settings                             [manage accounting settings]
│   ├── General (FY, method, account numbers, period close)
│   └── Tax & discount accounts (optional invoice split postings)
Reports (subsection — any report link requires one permission)
├── Profit & Loss                        [view accounting reports]
├── Balance Sheet                        [view accounting reports]
├── Cash Flow                            [view accounting reports]
├── Trial Balance                        [view accounting reports]
├── Account Statement                    [view accounting reports]
├── (Recommended) Journal activity by month [view accounting reports]
└── (Recommended) Custom reports         [view accounting reports]
```

**Optional (not in MAAMUUS today, recommended):**

```
Accounting
└── Dashboard / Overview                 [any of view accounting *]
    ├── KPI cards: unmapped count, recent journals, period status
    └── Quick links to Transactions (not_mapped), CoA bootstrap, Reports
```

**Page groupings**

| Group | Purpose |
|--------|---------|
| **Operations** | Transactions — bridge from host documents to journals |
| **Ledger** | Chart of accounts, journal entries, transfers |
| **Configuration** | Mapping setup, branch/accounting settings, bootstrap |
| **Reporting** | Financial statements and custom reports |

**Visibility**

- Sidebar items are **hidden** unless the user passes the listed permission (or your host’s equivalent, e.g. Spatie role checks).
- Mapping **module tabs** inside Mapping setup may be further gated by **host** operational permissions (e.g. only show Inventory tab if user can view purchase invoices). MAAMUUS uses `hasAny([...])` per tab; SaaS tab is **master-org only**.
- Actions (Add, Edit, Delete, Map, Bootstrap) are gated per action permission (see §3.7).

---

## 2. Page Reference

### 2.1 Dashboard / Overview (recommended)

**Purpose:** Landing page with shortcuts and KPIs (unmapped transactions, closed period warning, link to bootstrap).

**Route suggestion:** `/accounting` or `/accounting/dashboard`

**API endpoints used:**

- `GET /transactions?status=not_mapped&per_page=1` (count via pagination meta)
- `GET /accounts` (empty check)
- `GET /settings` (period closed flag) — `manage accounting settings`

**Key UI elements:** Stat cards, links to Transactions (not mapped), Chart of Accounts, Reports.

**Permission required:** At least one accounting view permission; settings card needs `manage accounting settings`.

**Empty state:** First-time: prompt to run bootstrap (CLI or settings) and open Mapping setup.

**MAAMUUS note:** No dedicated dashboard; users land on Chart of Accounts from the menu.

---

### 2.2 Chart of Accounts (list)

**Purpose:** Browse, search, export, create, edit, and soft-delete accounts; seed default CoA when empty.

**Route suggestion:** `/accounting/accounts`

**API endpoints used:**

- `GET /accounts` — list (`search_term`, `search_fields`, `sort_by`, `sort_direction`, `page`, `per_page`)
- `POST /accounts` — create
- `PATCH /accounts/{id}` — update
- `DELETE /accounts/{id}` — soft delete
- `POST /bootstrap` — seed defaults (query/body: `force` optional) — `manage accounting settings`
- Optional: `GET /accounts/trashed/list`, `POST /accounts/{id}/restore`, `DELETE /accounts/{id}/force`

**Key UI elements:**

- Search (name, number)
- Table columns: **Number**, **Name**, **Type**, **Balance** (`current_balance`), **Active**
- Row actions: Edit, Delete (trash)
- Links from Number/Name → Account Statement report with `?account_id=`
- Primary: **Add Account** (modal)
- Empty state banner: **Seed default CoA** button

**Form fields (add/edit modal):** `name` (required), `account_type`, `parent_id`, `is_active`; number is read-only/auto-assigned on create.

**Filters:** Search only (MAAMUUS loads up to 200 per page for hierarchy pickers).

**Permission required:** `view accounting accounts`; create/edit/delete per action.

**Empty state:** “No accounts yet” + bootstrap CTA if `manage accounting settings`.

**Single-tenant note:** HTTP `POST /bootstrap` may require host context; prefer `php artisan accounting:bootstrap` when `tenancy_mode` is `single_tenant` until your app exposes a working context on API calls.

---

### 2.3 Account Detail / Edit

**Purpose:** View or change one account (MAAMUUS: edit modal on list page).

**Route suggestion:** `/accounting/accounts/:id/edit` (optional) or modal on §2.2

**API:** `GET /accounts/{id}`, `PATCH /accounts/{id}`

**Permission:** `view accounting accounts` / `edit accounting accounts`

**Empty state:** N/A (404 from API).

---

### 2.4 Create Account

**Purpose:** Add a ledger account.

**Route suggestion:** `/accounting/accounts/create` or modal on §2.2

**API:** `POST /accounts` — body: `name`, `account_type`, `parent_id?`, `is_active?`

**Permission:** `create accounting accounts`

---

### 2.5 Transactions (list)

**Purpose:** Operational queue from host documents: filter, bulk map, open detail.

**Route suggestion:** `/accounting/transactions`

**API endpoints used:**

- `GET /transactions` — filters: `module`, `status` (`not_mapped` | `mapped` | `voided`), `date_from`, `date_to`, `include_voided` (required with `status=voided`), search on `description`, `reference`, `type`
- `POST /transactions/bulk-map` — `ids[]`, optional `debit_account_id`, `credit_account_id`
- `GET /accounts` — account pickers for bulk map
- Detail: `GET /transactions/{id}`

**Key UI elements:**

- Left rail: **module** filter (sales, hotel, restaurant, events, inventory, expenses, …) — host-defined labels OK
- Filters: **status**, **date_from**, **date_to**, search
- Table: select column, **Date**, **Type**, **Description**, **Amount**, **Status**, **Journal** (#), **View**
- **Bulk map** modal: debit/credit account selectors; blank accounts = resolve via mappings
- Status badges: not_mapped (warning), mapped (success), voided (danger)

**“Unmapped transactions”:** Same page with `status=not_mapped` (no separate route required).

**Permission:** `view accounting transactions`; bulk map → `edit accounting transactions`

**Empty state:** “No transactions in this period” with hint to post documents from host modules.

---

### 2.6 Transaction Detail

**Purpose:** Read-only inspection of amount, tax, discount, source morph, linked journal lines, void metadata.

**Route suggestion:** Drawer on §2.5 (MAAMUUS) or `/accounting/transactions/:id`

**API:** `GET /transactions/{id}` (include `journal_entry.lines` when API eager-loads)

**Optional actions (package supports; wire if product needs them):**

- `POST /transactions/{id}/map` — `edit accounting transactions`
- `POST /transactions/{id}/unmap` — `edit accounting transactions`

**Permission:** `view accounting transactions`

**Empty state:** “Select a transaction” in drawer before selection.

---

### 2.7 Journal Entries (list)

**Purpose:** Audit journal vouchers; filter by date; optional voided audit view.

**Route suggestion:** `/accounting/journal-entries`

**API:** `GET /journal-entries` — `date_from`, `date_to`, `include_voided`, search on `number`, `description`, `reference`

**Key UI elements:**

- Toggle **Include voided**
- Date range filters
- View mode: **Journal** (card per entry + line table) vs **List** (headers only)
- Columns (list mode): Number, Date, Description, Source (`manual` | `mapping` | `transfer`), Amount, Line count
- Source badges by type

**Permission:** `view accounting journal entries`

**Empty state:** “No journal entries yet.”

---

### 2.8 Journal Entry Detail

**Purpose:** Single entry with all lines (MAAMUUS: embedded in Journal view card).

**Route suggestion:** `/accounting/journal-entries/:id` (optional)

**API:** `GET /journal-entries/{id}`

**Permission:** `view accounting journal entries`

---

### 2.9 Create Manual Journal Entry

**Purpose:** Balanced multi-line journal (minimum two lines).

**Route suggestion:** Modal on §2.7 or `/accounting/journal-entries/create`

**API:**

- `GET /accounts` — active accounts for selectors
- `POST /journal-entries` — body: `journal_date`, `description?`, `reference?`, `lines[]` with `account_id`, `debit`, `credit`

**Form:** Date, description, dynamic lines (account, debit, credit), **running totals** and balanced indicator.

**Permission:** `create accounting journal entries`

**Validation UX:** Block submit unless debits = credits and total &gt; 0.

---

### 2.10 Transfers (list)

**Purpose:** List account-to-account transfers (each creates a transfer + 2-line JE).

**Route suggestion:** `/accounting/transfers`

**API:** `GET /transfers` — search/sort/pagination

**Table columns:** Date, From account, To account, Amount, Notes

**Permission:** `view accounting transfers`

**Empty state:** “No transfers yet” + create CTA.

---

### 2.11 Create Transfer

**Purpose:** Move balance between two distinct accounts.

**Route suggestion:** Modal on §2.10 or `/accounting/transfers/create`

**API:**

- `GET /accounts` — prefer cash/bank types first in UI
- `POST /transfers` — `from_account_id`, `to_account_id`, `amount`, `transfer_date`, `notes?`

**Permission:** `create accounting transfers`

**Delete (if exposed):** `DELETE /transfers/{id}` — `delete accounting transfers`

---

### 2.12 Mapping Setup

**Route suggestion:** `/accounting/mappings`

**API (all pages):**

- `GET /document-mappings`
- `POST /document-mappings/upsert`
- `GET /payment-method-mappings`
- `POST /payment-method-mappings/upsert`
- `GET /mappings/payment-methods/{id}/sides`
- `POST|PUT|DELETE` sides under `/mappings/payment-methods/{id}/sides`
- `GET /expense-category-mappings`
- `POST /expense-category-mappings/upsert`
- `GET /registry/documents` — document types per module (no extra permission)
- `GET /accounts` — selectors
- **Host (not package):** list payment methods & expense categories from your app’s APIs/models

**Permission:** `view accounting mappings`; saves → `edit accounting mappings`

#### 2.12.1 Document Mappings (dynamic — from registry)

Module tabs: build from `GET /registry/documents` (group by `module`). Each row: human label (`document_type`), **enable**, **debit account**, **credit account**. Save per tab or batch upsert.

#### 2.12.2 Payment Method Mappings

List host **payment methods** merged with `GET /payment-method-mappings`. Per method: enable, debit/credit (legacy sales/purchase columns and/or **module sides** via sides API). MAAMUUS uses sales/purchase pairs; package supports arbitrary **module** sides.

#### 2.12.3 Expense Category Mappings

List host **expense categories** + `GET /expense-category-mappings`. Per row: enable, debit, credit.

#### 2.12.4 General / Settings tab

**MAAMUUS:** “General” tab = payment methods + expense categories on Mapping page.

**Tax/discount accounts** for invoice line splits live on **Settings** (§2.14): `tax_payable_account_id`, `tax_receivable_account_id`, `sales_discount_account_id`, `purchase_discount_account_id` via `PATCH /settings`.

**Empty state:** “Configure mappings so automatic posting can resolve debit/credit accounts.”

---

### 2.13 Reports

Shared shell (MAAMUUS: `AccountingReportLayout` + report tabs). Permission: `view accounting reports` for all.

#### 2.13.1 Trial Balance

**Route:** `/accounting/reports/trial-balance`

**API:** `GET /reports/trial-balance?as_of=YYYY-MM-DD`

**UI:** As-of date (not a range). Columns: Number, Account, Debit, Credit; totals row; balanced indicator.

#### 2.13.2 Profit and Loss

**Route:** `/accounting/reports/profit-and-loss`

**API:** `GET /reports/profit-and-loss?date_from&date_to`

**UI:** Date range; grouped sections (income, COGS, expenses); net income total.

#### 2.13.3 Balance Sheet

**Route:** `/accounting/reports/balance-sheet`

**API:** `GET /reports/balance-sheet?as_of&fy_start?` (optional fiscal year start)

**UI:** As-of date; assets / liabilities / equity sections.

#### 2.13.4 Cash Flow

**Route:** `/accounting/reports/cash-flow`

**API:** `GET /reports/cash-flow?date_from&date_to`

**UI:** Range; operating / investing / financing style rows per API shape.

#### 2.13.5 Account Statement

**Route:** `/accounting/reports/account-statement?account_id=`

**API:** `GET /reports/account-statement?account_id&date_from&date_to`

**UI:** Account selector + range; running balance column; link from CoA.

#### 2.13.6 Journal Activity by Month

**Route:** `/accounting/reports/journal-activity-by-month` (recommended; not in MAAMUUS UI yet)

**API:** `GET /reports/journal-activity-by-month?date_from&date_to&limit?`

**UI:** Range; monthly buckets with debit/credit totals.

#### 2.13.7 Custom Reports (dynamic — from registry)

**Routes:** `/accounting/reports/custom`, `/accounting/reports/custom/:name`

**API:** `GET /reports/custom`, `GET /reports/custom/{name}?{filters}`

**UI:** List registered reports; render filter form from handler validation rules; display JSON rows as table.

---

### 2.14 Settings / Branch Settings

**Purpose:** Fiscal year start month, accrual/cash, account numbers toggle, **close the books** + closing date, tax/discount GL accounts.

**Route suggestion:** `/accounting/settings`

**API:**

- `GET /settings`
- `PATCH /settings` — full settings payload
- Optional dedicated: `PATCH /settings/close-period`, `PATCH /settings/reopen-period`

**Permission:** `manage accounting settings`

**Empty state:** Defaults until first bootstrap creates `accounting_branch_settings` row.

---

### 2.15 Period Controls (close/reopen books)

**Purpose:** Block posting on or before `closing_date` when `close_the_books` is true.

**UI placement:** Section on §2.14 (toggle + closing date) or dedicated admin page.

**API:**

- `PATCH /settings` with `close_the_books`, `closing_date`
- Or `PATCH /settings/close-period` body `{ "closing_date": "YYYY-MM-DD" }`
- `PATCH /settings/reopen-period`

**Permission:** `manage accounting settings`

**UX:** Show banner site-wide in accounting when period is closed; surface API `AccountingPeriodClosedException` messages on save.

---

## 3. Shared UI Patterns

### 3.1 Account Selector

- **Used in:** journal entry form, mapping setup, transfer form, bulk map, account statement, settings tax accounts
- **Data source:** `GET /accounts` (filter `is_active` client-side if needed)
- **Display format:** `{number} — {name} ({account_type})` (MAAMUUS: `formatAccountingAccountLabel`)
- **Search/filter:** Typeahead or dropdown filter on label
- **Group by:** `account_type` optional for long lists

### 3.2 Date Range Filter

- **Used in:** transactions, journal entries, P&amp;L, cash flow, account statement, journal activity
- **Fields:** `date_from`, `date_to` (ISO `YYYY-MM-DD`)
- **Presets:** This month (default in MAAMUUS reports), last 30 days (transactions default), last month, quarter, year, custom
- **As-of reports:** Trial balance, balance sheet use `as_of` (= `date_to` only)

### 3.3 Status Badge

| Entity | Values | Suggested color |
|--------|--------|-----------------|
| Transaction | `not_mapped` | warning |
| Transaction | `mapped` | success |
| Transaction | `voided` | danger/muted |
| Journal entry | `active` | success |
| Journal entry | `voided` | danger/muted |

### 3.4 Void indicator

- Voided rows: strikethrough or muted text + badge
- Lists: **Include voided** toggle (`include_voided=1`) — default hide voided transactions/journals

### 3.5 Amount display

- Host currency from `config('accounting.currency')` or organization settings
- Journal lines: separate **Debit** and **Credit** columns; show em dash when zero
- Manual JE form: live totals + “Balanced” / difference warning

### 3.6 Pagination

- Query: `page`, `per_page` (MAAMUUS often uses 100–200 for ops lists, 500 for account pickers)
- Show total count from API pagination meta when available
- Page size selector: 10, 25, 50, 100

### 3.7 Permission-gated actions

| Action | Permission |
|--------|------------|
| View CoA | `view accounting accounts` |
| Create account | `create accounting accounts` |
| Edit / restore account | `edit accounting accounts` |
| Delete account | `delete accounting accounts` |
| Bootstrap / seed CoA (HTTP) | `manage accounting settings` |
| View transactions | `view accounting transactions` |
| Map / bulk map / unmap | `edit accounting transactions` |
| View journals | `view accounting journal entries` |
| Create manual JE | `create accounting journal entries` |
| Edit / void JE (if exposed) | `edit accounting journal entries` |
| View transfers | `view accounting transfers` |
| Create transfer | `create accounting transfers` |
| Delete transfer | `delete accounting transfers` |
| View mappings | `view accounting mappings` |
| Save mappings | `edit accounting mappings` |
| View reports | `view accounting reports` |
| Settings & period close | `manage accounting settings` |

---

## 4. Mapping Setup UI (special attention)

### 4.1 Document tabs from `GET /registry/documents`

1. Fetch registry (modules + document types + default debit/credit key_names + mapping status if API provides).
2. Group rows by `module` → one tab per module (capitalize label).
3. Each document type row:
   - **Enable** toggle (`is_enabled`)
   - **Debit account** selector
   - **Credit account** selector
4. Load existing: `GET /document-mappings`; merge by `(module, document_type)`.
5. Save: `POST /document-mappings/upsert` per row or batched.

**MAAMUUS:** Uses a static `TAB_CARDS` map aligned with host modules; prefer **registry-driven** tabs for portable apps.

**Tab visibility:** Optionally hide modules the user cannot operate (host permissions), e.g. hide Payroll tab without `view payroll`.

### 4.2 Payment Method Mappings tab

1. Load host payment methods (active).
2. Load `GET /payment-method-mappings`.
3. For each method show:
   - Enable + default debit/credit (sales/purchase legacy fields), **or**
   - Table of **sides**: `GET /mappings/payment-methods/{mappingId}/sides` — module name, debit, credit, enable
4. Add side: `POST .../sides` — `module`, `debit_account_id`, `credit_account_id`, `is_enabled`
5. Update: `PUT .../sides/{module}`; delete: `DELETE .../sides/{module}`

Show indicators: “Sales configured”, “Purchase missing”, etc.

### 4.3 Expense Category Mappings tab

1. Load host expense categories.
2. Load `GET /expense-category-mappings`.
3. Per category: enable, debit, credit → `POST /expense-category-mappings/upsert`.

### 4.4 General tab

**On Settings page (recommended for tax/discount GL):**

- Tax payable, tax receivable, sales discount, purchase discount account selectors
- Save: `PATCH /settings`

**On Mapping page (MAAMUUS “General” tab):** payment methods + expense categories only.

---

## 5. Reports UI Pattern

### 5.1 Report layout structure

1. Title + subtitle + refresh
2. Horizontal **report tabs** (switch between statement types without leaving accounting reports)
3. Filters row: date range **or** as-of; extra filters (account picker for statement)
4. Optional **stat cards** (totals, balanced flag, account count)
5. Export (CSV/PDF — implemented in host; export from loaded JSON rows in MAAMUUS)
6. Statement table + **totals row**

### 5.2 Per-report specifics

| Report | Filters | Endpoint | Columns / grouping | Totals |
|--------|---------|----------|-------------------|--------|
| Trial balance | `as_of` | `/reports/trial-balance` | Flat accounts; Debit, Credit | Total debit & credit; balance check |
| Profit &amp; loss | `date_from`, `date_to` | `/reports/profit-and-loss` | Sections by account type / group | Net income |
| Balance sheet | `as_of`, optional `fy_start` | `/reports/balance-sheet` | Assets, liabilities, equity | Section subtotals |
| Cash flow | `date_from`, `date_to` | `/reports/cash-flow` | Category rows per service | Net change in cash |
| Account statement | `account_id`, `date_from`, `date_to` | `/reports/account-statement` | Date, reference, debit, credit, balance | Opening/closing balance |
| Journal activity | `date_from`, `date_to`, `limit` | `/reports/journal-activity-by-month` | Month, entry count, debits, credits | Period totals |

See [KNOWLEDGE.md](KNOWLEDGE.md) for exact JSON response shapes.

### 5.3 Custom reports

1. `GET /reports/custom` → list `{ name, label, description?, ... }`
2. For each report, `GET /reports/custom/{name}` with query params matching `rules()` on the registered handler
3. On 422, render field errors next to generated inputs
4. Host registers handlers in `AccountingReportRegistry`

---

## 6. Framework Implementation Examples

### 6.1 React (React Router)

```
/accounting/accounts
/accounting/transactions
/accounting/journal-entries
/accounting/transfers
/accounting/mappings
/accounting/settings
/accounting/reports/profit-and-loss
/accounting/reports/balance-sheet
/accounting/reports/cash-flow
/accounting/reports/trial-balance
/accounting/reports/account-statement
/accounting/reports/journal-activity-by-month
/accounting/reports/custom
/accounting/reports/custom/:name
```

Optional: `/accounting/accounts/create`, `/accounting/accounts/:id/edit`, `/accounting/journal-entries/create`, `/accounting/transfers/create`, `/accounting/transactions/:id`

### 6.2 Vue (Vue Router)

```js
{
  path: '/accounting',
  children: [
    { path: 'accounts', component: AccountsPage },
    { path: 'transactions', component: TransactionsPage },
    { path: 'journal-entries', component: JournalEntriesPage },
    { path: 'transfers', component: TransfersPage },
    { path: 'mappings', component: MappingsPage },
    { path: 'settings', component: SettingsPage },
    { path: 'reports/trial-balance', component: TrialBalancePage },
  ],
}
```

### 6.3 Laravel Blade

```php
Route::middleware(['auth', 'permission:view accounting accounts'])->prefix('accounting')->group(function () {
    Route::view('accounts', 'accounting.accounts.index');
    Route::view('transactions', 'accounting.transactions.index');
    Route::view('journal-entries', 'accounting.journal-entries.index');
    Route::view('transfers', 'accounting.transfers.index');
    Route::view('mappings', 'accounting.mappings.index')
        ->middleware('permission:view accounting mappings');
    Route::view('settings', 'accounting.settings.index')
        ->middleware('permission:manage accounting settings');
    Route::view('reports/trial-balance', 'accounting.reports.trial-balance')
        ->middleware('permission:view accounting reports');
    // ... other reports
});
```

Blade pages call the same JSON API via `fetch` or Livewire/Alpine; see [BLADE_INTEGRATION.md](BLADE_INTEGRATION.md).

### 6.4 Next.js (App Router)

```
app/
  accounting/
    accounts/
      page.tsx
    transactions/
      page.tsx
    journal-entries/
      page.tsx
    transfers/
      page.tsx
    mappings/
      page.tsx
    settings/
      page.tsx
    reports/
      trial-balance/page.tsx
      profit-and-loss/page.tsx
      balance-sheet/page.tsx
      cash-flow/page.tsx
      account-statement/page.tsx
      journal-activity-by-month/page.tsx
      custom/
        page.tsx
        [name]/page.tsx
```

---

## 7. Theming and Customization

### 7.1 The package does not ship UI components

Developers build their own UI consuming the API. No CSS or component library is imposed.

### 7.2 Suggested design tokens

Define in the host app so accounting matches the rest of the product:

- Primary — buttons, active nav, selected module tab
- Success — mapped / active status
- Warning — not_mapped status
- Danger — voided status, unbalanced trial balance
- Surface/card — list shells, journal voucher cards
- Border — tables and dividers
- Font family — inherit from host

### 7.3 Minimum recommended UI requirements

- Mobile-responsive tables (horizontal scroll on small screens)
- Loading states on all API calls
- Error states with retry
- Confirmation before void/delete
- Toast/notification after save, map, bootstrap, period close

---

## 8. API Quick Reference for UI Developers

Prefix: `{route_prefix}` (default `api/v1/accounting`). Middleware: `config('accounting.middleware')` + `permission:…`.

Common list query params (where supported): `page`, `per_page`, `search_term`, `search_fields[]`, `sort_by`, `sort_direction`.

| Method | Path | Used on | Key params / body |
|--------|------|---------|-------------------|
| GET | `/registry/documents` | Mapping setup | — |
| POST | `/bootstrap` | CoA empty state | `force` (bool) — `manage accounting settings` |
| GET | `/settings` | Settings | — |
| PATCH | `/settings` | Settings | FY month, `accounting_method`, `enable_account_numbers`, `close_the_books`, `closing_date`, tax/discount account ids |
| PATCH | `/settings/close-period` | Period close | `closing_date` |
| PATCH | `/settings/reopen-period` | Period reopen | — |
| GET | `/accounts` | CoA, selectors, reports | search, sort, pagination |
| POST | `/accounts` | Create account | `name`, `account_type`, `parent_id`, `is_active` |
| GET | `/accounts/{id}` | Account detail | — |
| PATCH | `/accounts/{id}` | Edit account | same as create |
| DELETE | `/accounts/{id}` | Trash account | — |
| GET | `/accounts/trashed/list` | Trash bin UI | pagination |
| POST | `/accounts/{id}/restore` | Restore | — |
| DELETE | `/accounts/{id}/force` | Force delete | — |
| GET | `/transactions` | Transactions | `module`, `status`, `date_from`, `date_to`, `include_voided` |
| GET | `/transactions/{id}` | Tx detail | — |
| POST | `/transactions/bulk-map` | Bulk map | `ids`, `debit_account_id?`, `credit_account_id?` |
| POST | `/transactions/{id}/map` | Single map | debit/credit or mapping hints |
| POST | `/transactions/{id}/unmap` | Unmap | — |
| GET | `/journal-entries` | Journal list | `date_from`, `date_to`, `include_voided` |
| POST | `/journal-entries` | Manual JE | `journal_date`, `description`, `lines[]` |
| GET | `/journal-entries/{id}` | JE detail | — |
| PATCH | `/journal-entries/{id}` | Edit JE | if enabled |
| DELETE | `/journal-entries/{id}` | Void JE | if enabled |
| GET | `/transfers` | Transfers list | search, pagination |
| POST | `/transfers` | Create transfer | `from_account_id`, `to_account_id`, `amount`, `transfer_date`, `notes` |
| GET | `/transfers/{id}` | Transfer detail | — |
| DELETE | `/transfers/{id}` | Delete transfer | — |
| GET | `/document-mappings` | Mapping | pagination |
| POST | `/document-mappings/upsert` | Mapping save | `module`, `document_type`, account ids, `is_enabled` |
| GET | `/payment-method-mappings` | Mapping | pagination |
| POST | `/payment-method-mappings/upsert` | Mapping save | `payment_method_id`, account ids, `is_enabled` |
| GET | `/mappings/payment-methods/{id}/sides` | PM sides | — |
| POST | `/mappings/payment-methods/{id}/sides` | Add side | `module`, accounts, `is_enabled` |
| PUT | `/mappings/payment-methods/{id}/sides/{module}` | Update side | accounts, `is_enabled` |
| DELETE | `/mappings/payment-methods/{id}/sides/{module}` | Remove side | — |
| GET | `/expense-category-mappings` | Mapping | pagination |
| POST | `/expense-category-mappings/upsert` | Mapping save | `expense_category_id`, accounts, `is_enabled` |
| GET | `/reports/trial-balance` | Trial balance | `as_of` |
| GET | `/reports/profit-and-loss` | P&amp;L | `date_from`, `date_to` |
| GET | `/reports/balance-sheet` | Balance sheet | `as_of`, `fy_start?` |
| GET | `/reports/cash-flow` | Cash flow | `date_from`, `date_to` |
| GET | `/reports/account-statement` | Statement | `account_id` (required), `date_from`, `date_to` |
| GET | `/reports/journal-activity-by-month` | Activity | `date_from`, `date_to`, `limit?` |
| GET | `/reports/custom` | Custom list | — |
| GET | `/reports/custom/{name}` | Run custom | dynamic per report `rules()` |

Full request/response documentation: [KNOWLEDGE.md](KNOWLEDGE.md).