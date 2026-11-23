# Using abdiaziizaweis/accounting in Blade Laravel Apps

## Overview

The package exposes all functionality through **injectable services** registered by `AccountingServiceProvider`. You do not need to call the HTTP API from a traditional Blade monolith — invoke services directly from your web controllers, jobs, or listeners.

Package routes in `routes/api.php` remain available if you want JSON for AJAX or partials, but they are optional for same-process Blade apps.

**Context:** Services that scope data by organization/branch expect `AccountingContextInterface` to be resolved. For Blade, either:

- Include `Abdiaziizaweis\Accounting\Http\Middleware\ResolveAccountingContext` on your web routes (alias `accounting.context`), or
- Bind a resolver in `AppServiceProvider` when using `single_tenant` (minimal fixed context).

---

## Direct service injection examples

### Posting a document from a controller

Your sale model implements `AccountingDocumentInterface`, or you wrap it in a small adapter:

```php
namespace App\Http\Controllers;

use App\Models\Sale;
use App\Accounting\SaleAccountingDocument;
use Abdiaziizaweis\Accounting\Services\AccountingDocumentBridge;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function store(Request $request, AccountingDocumentBridge $bridge)
    {
        $sale = Sale::create($request->validated());
        $bridge->record(new SaleAccountingDocument($sale));

        return redirect()->route('sales.index');
    }

    public function destroy(Sale $sale, AccountingDocumentBridge $bridge)
    {
        $bridge->voidFor(new SaleAccountingDocument($sale), strict: true);
        $sale->delete();

        return redirect()->route('sales.index');
    }
}
```

Alternatively, use the `HasAccountingDocument` trait on the model so `saved` / `deleting` events call the bridge automatically (see `docs/EXAMPLE_INTEGRATION.md`).

### Getting reports in a Blade controller

`AccountingReportService` accepts `Carbon` dates (not filter arrays):

```php
namespace App\Http\Controllers;

use Abdiaziizaweis\Accounting\Services\AccountingReportService;
use Carbon\Carbon;

class AccountingReportController extends Controller
{
    public function trialBalance(AccountingReportService $reports)
    {
        $data = $reports->trialBalance(now()->endOfDay());

        return view('accounting.trial-balance', compact('data'));
    }

    public function profitAndLoss(AccountingReportService $reports)
    {
        $data = $reports->profitAndLoss(
            now()->startOfMonth()->startOfDay(),
            now()->endOfDay(),
        );

        return view('accounting.profit-loss', compact('data'));
    }
}
```

Other reports: `balanceSheet($asOf, $fyStart?)`, `cashFlow($from, $to)`, `accountStatement($accountId, $from, $to)`, `journalActivityByMonth($from, $to, $limit)`.

### Managing chart of accounts directly

```php
namespace App\Http\Controllers;

use Abdiaziizaweis\Accounting\Models\AccountingAccount;

class AccountController extends Controller
{
    public function index()
    {
        $accounts = AccountingAccount::query()
            ->where('is_active', true)
            ->orderBy('number')
            ->get();

        return view('accounting.accounts', compact('accounts'));
    }
}
```

Use package services for writes when you need validation and period guards, for example `AccountingJournalService::createManualEntry()`, `AccountingTransferService::create()`, `AccountingTransactionService::mapWithAccounts()`.

---

## Setting up auth for Blade apps

In `config/accounting.php`:

```php
'middleware' => [
    'auth', // Laravel session guard — typical for Blade
    \Abdiaziizaweis\Accounting\Http\Middleware\ResolveAccountingContext::class,
],
```

Package **HTTP** routes use this stack. Your **web** routes use Laravel’s normal `web` + `auth` middleware; service injection does not require Sanctum.

Default tenancy for new installs is `single_tenant` (no `organizations` / `branches` tables). See `docs/KNOWLEDGE.md` §4.

---

For documents with complex accounting (COGS, tax, payroll deductions), implement **`getJournalLines()`** on your model instead of relying on two-account mapping. Return `null` to keep mapping mode. See [KNOWLEDGE.md §23](KNOWLEDGE.md).

## Setting up permissions for Blade apps

Package permission names (e.g. `view accounting reports`) are checked via `AccountingAuthInterface::can()` when you use package HTTP routes or `AccountingPermissionMiddleware` on your own routes.

### Option A — Laravel Gates (no extra package)

In `AppServiceProvider::boot()` or `AuthServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('view accounting reports', fn ($user) => (bool) $user->is_admin);
Gate::define('manage accounting settings', fn ($user) => (bool) $user->is_admin);
// Define other accounting permission strings as needed (see README).
```

`DefaultAccountingAuth` uses `Gate::allows()` and `$user->can()`.

### Option B — Spatie Permission (if already installed)

Assign the same permission strings to roles; `$user->can('view accounting reports')` works with no custom binding.

### Option C — Custom `AccountingAuthInterface`

```php
namespace App\Accounting;

use Abdiaziizaweis\Accounting\Contracts\AccountingAuthInterface;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;

class RoleBasedAccountingAuth implements AccountingAuthInterface
{
    public function can(string $permission): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        return $user->role === 'admin';
    }

    public function user(): Authenticatable
    {
        $user = auth()->user();
        if (! $user instanceof Authenticatable) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return $user;
    }

    public function userOrNull(): ?Authenticatable
    {
        $user = auth()->user();

        return $user instanceof Authenticatable ? $user : null;
    }
}
```

Register in `AppServiceProvider::register()`:

```php
$this->app->bind(
    \Abdiaziizaweis\Accounting\Contracts\AccountingAuthInterface::class,
    \App\Accounting\RoleBasedAccountingAuth::class,
);
```

---

## When to use HTTP API vs direct services

| Situation | Recommendation |
|-----------|----------------|
| Blade app, same Laravel instance | Direct service injection |
| SPA (React, Vue, Inertia API) | HTTP API and/or services |
| External app (Node.js, Python) | HTTP API as integration boundary |
| Blade app + accounting UI in iframe | HTTP API from the iframe origin |
| Artisan commands / queues | Direct service injection |

---

## See also

- [EXAMPLE_INTEGRATION.md](./EXAMPLE_INTEGRATION.md) — document interface + registry
- [KNOWLEDGE.md](./KNOWLEDGE.md) — full service and schema reference
- [README.md](../README.md) — installation and usage patterns