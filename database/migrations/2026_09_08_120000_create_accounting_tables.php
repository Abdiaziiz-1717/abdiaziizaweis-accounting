<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Abdiaziizaweis\Accounting\Database\Migration\TenancySchemaHelper;
use Abdiaziizaweis\Accounting\Services\AccountingCoaSeeder;

/**
 * Branch-scoped Accounting module foundation (CoA, JEs, mappings, transfers, reconciles).
 * FK/index names are shortened for MySQL's 64-char identifier limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->id();
            TenancySchemaHelper::addOrganizationColumn($table);
            TenancySchemaHelper::addBranchColumn($table);
            $table->foreignId('owner_user_id')->nullable()->constrained(TenancySchemaHelper::usersTable())->nullOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->string('number', 40)->nullable();
            $table->string('key_name', 80)->nullable();
            $table->string('account_type', 40);
            $table->string('detail_type', 80)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('parent_id', 'acct_accounts_parent_fk')
                ->references('id')->on('accounting_accounts')->nullOnDelete();
            TenancySchemaHelper::addOrgBranchIndex($table, 'acct_accounts_org_branch_idx');
            if (TenancySchemaHelper::usesBranchId()) {
                $table->unique(['branch_id', 'name'], 'acct_accounts_branch_name_uq');
                $table->index(['branch_id', 'key_name'], 'acct_accounts_branch_key_idx');
                $table->index(['branch_id', 'account_type'], 'acct_accounts_branch_type_idx');
            } elseif (TenancySchemaHelper::usesOrganizationId()) {
                $table->unique(['organization_id', 'name'], 'acct_accounts_org_name_uq');
                $table->index(['organization_id', 'key_name'], 'acct_accounts_org_key_idx');
                $table->index(['organization_id', 'account_type'], 'acct_accounts_org_type_idx');
            } else {
                $table->unique('name', 'acct_accounts_name_uq');
                $table->index('key_name', 'acct_accounts_key_idx');
                $table->index('account_type', 'acct_accounts_type_idx');
            }
        });

        Schema::create('accounting_branch_settings', function (Blueprint $table) {
            $table->id();
            TenancySchemaHelper::addOrganizationColumn($table);
            TenancySchemaHelper::addBranchColumn($table, unique: true);
            $table->unsignedTinyInteger('first_month_financial_year')->default(1);
            $table->string('accounting_method', 20)->default('accrual'); // accrual|cash
            $table->boolean('close_the_books')->default(false);
            $table->date('closing_date')->nullable();
            $table->boolean('enable_account_numbers')->default(false);
            $table->timestamps();

            TenancySchemaHelper::addOrganizationIndex($table);
            if (TenancySchemaHelper::usesOrganizationId() && ! TenancySchemaHelper::usesBranchId()) {
                $table->unique('organization_id', 'acct_branch_settings_org_uq');
            }
        });

        Schema::create('accounting_journal_entries', function (Blueprint $table) {
            $table->id();
            TenancySchemaHelper::addOrganizationColumn($table);
            TenancySchemaHelper::addBranchColumn($table);
            $table->foreignId('owner_user_id')->nullable()->constrained(TenancySchemaHelper::usersTable())->nullOnDelete();
            $table->string('number', 40);
            $table->date('journal_date');
            $table->text('description')->nullable();
            $table->string('reference', 120)->nullable();
            $table->string('source', 40); // mapping|manual|transfer|reconcile_adjustment
            $table->decimal('amount', 15, 2);
            $table->unsignedBigInteger('accounting_transfer_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            TenancySchemaHelper::addOrgBranchIndex($table, 'acct_je_org_branch_idx');
            if (TenancySchemaHelper::usesBranchId()) {
                $table->index(['branch_id', 'journal_date'], 'acct_je_branch_date_idx');
                $table->index(['branch_id', 'number'], 'acct_je_branch_number_idx');
            } elseif (TenancySchemaHelper::usesOrganizationId()) {
                $table->index(['organization_id', 'journal_date'], 'acct_je_org_date_idx');
                $table->index(['organization_id', 'number'], 'acct_je_org_number_idx');
            } else {
                $table->index('journal_date', 'acct_je_date_idx');
                $table->index('number', 'acct_je_number_idx');
            }
            $table->index('accounting_transfer_id', 'acct_je_transfer_idx');
        });

        Schema::create('accounting_reconciles', function (Blueprint $table) {
            $table->id();
            TenancySchemaHelper::addOrganizationColumn($table);
            TenancySchemaHelper::addBranchColumn($table);
            $table->foreignId('owner_user_id')->nullable()->constrained(TenancySchemaHelper::usersTable())->nullOnDelete();
            $table->unsignedBigInteger('account_id');
            $table->decimal('beginning_balance', 15, 2);
            $table->decimal('ending_balance', 15, 2);
            $table->date('ending_date');
            $table->string('status', 20)->default('in_progress'); // in_progress|finished
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id', 'acct_rec_account_fk')
                ->references('id')->on('accounting_accounts')->restrictOnDelete();
            TenancySchemaHelper::addOrgBranchIndex($table, 'acct_rec_org_branch_idx');
            if (TenancySchemaHelper::usesBranchId()) {
                $table->index(['branch_id', 'account_id', 'status'], 'acct_rec_branch_acct_status_idx');
            } elseif (TenancySchemaHelper::usesOrganizationId()) {
                $table->index(['organization_id', 'account_id', 'status'], 'acct_rec_org_acct_status_idx');
            } else {
                $table->index(['account_id', 'status'], 'acct_rec_acct_status_idx');
            }
        });

        Schema::create('accounting_journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('account_id');
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('memo')->nullable();
            $table->unsignedBigInteger('accounting_reconcile_id')->nullable();
            $table->boolean('is_reconciled')->default(false);
            $table->timestamps();

            $table->foreign('journal_entry_id', 'acct_jel_je_fk')
                ->references('id')->on('accounting_journal_entries')->cascadeOnDelete();
            $table->foreign('account_id', 'acct_jel_account_fk')
                ->references('id')->on('accounting_accounts')->restrictOnDelete();
            $table->foreign('accounting_reconcile_id', 'acct_jel_rec_fk')
                ->references('id')->on('accounting_reconciles')->nullOnDelete();
            $table->index(['account_id', 'is_reconciled'], 'acct_jel_acct_recon_idx');
            $table->index('accounting_reconcile_id', 'acct_jel_rec_idx');
        });

        Schema::create('accounting_transactions', function (Blueprint $table) {
            $table->id();
            TenancySchemaHelper::addOrganizationColumn($table);
            TenancySchemaHelper::addBranchColumn($table);
            $table->foreignId('owner_user_id')->nullable()->constrained(TenancySchemaHelper::usersTable())->nullOnDelete();
            $table->string('transactionable_type')->nullable();
            $table->unsignedBigInteger('transactionable_id')->nullable();
            $table->string('type', 60);
            $table->string('status', 20)->default('not_mapped'); // mapped|not_mapped
            $table->decimal('amount', 15, 2);
            $table->date('transaction_date');
            $table->text('description')->nullable();
            $table->string('reference', 120)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('journal_entry_id', 'acct_tx_je_fk')
                ->references('id')->on('accounting_journal_entries')->nullOnDelete();
            TenancySchemaHelper::addOrgBranchIndex($table, 'acct_tx_org_branch_idx');
            $table->index(['transactionable_type', 'transactionable_id'], 'acct_tx_morph_idx');
            if (TenancySchemaHelper::usesBranchId()) {
                $table->index(['branch_id', 'status'], 'acct_tx_branch_status_idx');
                $table->index(['branch_id', 'type'], 'acct_tx_branch_type_idx');
            } elseif (TenancySchemaHelper::usesOrganizationId()) {
                $table->index(['organization_id', 'status'], 'acct_tx_org_status_idx');
                $table->index(['organization_id', 'type'], 'acct_tx_org_type_idx');
            } else {
                $table->index('status', 'acct_tx_status_idx');
                $table->index('type', 'acct_tx_type_idx');
            }
        });

        Schema::create('accounting_document_mappings', function (Blueprint $table) {
            $table->id();
            TenancySchemaHelper::addOrganizationColumn($table);
            TenancySchemaHelper::addBranchColumn($table);
            $table->string('module', 40);
            $table->string('document_type', 40);
            // Perfex: debit = deposit_to style, credit = payment_account style
            $table->unsignedBigInteger('debit_account_id');
            $table->unsignedBigInteger('credit_account_id');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->foreign('debit_account_id', 'acct_doc_debit_fk')
                ->references('id')->on('accounting_accounts')->restrictOnDelete();
            $table->foreign('credit_account_id', 'acct_doc_credit_fk')
                ->references('id')->on('accounting_accounts')->restrictOnDelete();
            if (TenancySchemaHelper::usesBranchId()) {
                $table->unique(['branch_id', 'module', 'document_type'], 'acct_doc_map_branch_module_type_uq');
            } elseif (TenancySchemaHelper::usesOrganizationId()) {
                $table->unique(['organization_id', 'module', 'document_type'], 'acct_doc_map_org_module_type_uq');
            } else {
                $table->unique(['module', 'document_type'], 'acct_doc_map_module_type_uq');
            }
            TenancySchemaHelper::addOrganizationIndex($table);
        });

        Schema::create('accounting_payment_method_mappings', function (Blueprint $table) {
            $table->id();
            TenancySchemaHelper::addOrganizationColumn($table);
            TenancySchemaHelper::addBranchColumn($table);
            $table->unsignedBigInteger('payment_method_id');
            $table->unsignedBigInteger('sales_debit_account_id');
            $table->unsignedBigInteger('sales_credit_account_id');
            $table->unsignedBigInteger('expense_debit_account_id')->nullable();
            $table->unsignedBigInteger('expense_credit_account_id')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->foreign('payment_method_id', 'acct_pm_method_fk')
                ->references('id')->on(TenancySchemaHelper::paymentMethodsTable())->cascadeOnDelete();
            $table->foreign('sales_debit_account_id', 'acct_pm_sales_debit_fk')
                ->references('id')->on('accounting_accounts')->restrictOnDelete();
            $table->foreign('sales_credit_account_id', 'acct_pm_sales_credit_fk')
                ->references('id')->on('accounting_accounts')->restrictOnDelete();
            $table->foreign('expense_debit_account_id', 'acct_pm_exp_debit_fk')
                ->references('id')->on('accounting_accounts')->restrictOnDelete();
            $table->foreign('expense_credit_account_id', 'acct_pm_exp_credit_fk')
                ->references('id')->on('accounting_accounts')->restrictOnDelete();
            if (TenancySchemaHelper::usesBranchId()) {
                $table->unique(['branch_id', 'payment_method_id'], 'acct_pm_map_branch_pm_uq');
            } elseif (TenancySchemaHelper::usesOrganizationId()) {
                $table->unique(['organization_id', 'payment_method_id'], 'acct_pm_map_org_pm_uq');
            } else {
                $table->unique('payment_method_id', 'acct_pm_map_pm_uq');
            }
            TenancySchemaHelper::addOrganizationIndex($table);
        });

        Schema::create('accounting_expense_category_mappings', function (Blueprint $table) {
            $table->id();
            TenancySchemaHelper::addOrganizationColumn($table);
            TenancySchemaHelper::addBranchColumn($table);
            $table->unsignedBigInteger('expense_category_id');
            $table->unsignedBigInteger('debit_account_id');
            $table->unsignedBigInteger('credit_account_id');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->foreign('expense_category_id', 'acct_exp_cat_fk')
                ->references('id')->on(TenancySchemaHelper::expenseCategoriesTable())->cascadeOnDelete();
            $table->foreign('debit_account_id', 'acct_exp_debit_fk')
                ->references('id')->on('accounting_accounts')->restrictOnDelete();
            $table->foreign('credit_account_id', 'acct_exp_credit_fk')
                ->references('id')->on('accounting_accounts')->restrictOnDelete();
            if (TenancySchemaHelper::usesBranchId()) {
                $table->unique(['branch_id', 'expense_category_id'], 'acct_exp_cat_map_branch_cat_uq');
            } elseif (TenancySchemaHelper::usesOrganizationId()) {
                $table->unique(['organization_id', 'expense_category_id'], 'acct_exp_cat_map_org_cat_uq');
            } else {
                $table->unique('expense_category_id', 'acct_exp_cat_map_cat_uq');
            }
            TenancySchemaHelper::addOrganizationIndex($table);
        });

        Schema::create('accounting_transfers', function (Blueprint $table) {
            $table->id();
            TenancySchemaHelper::addOrganizationColumn($table);
            TenancySchemaHelper::addBranchColumn($table);
            $table->foreignId('owner_user_id')->nullable()->constrained(TenancySchemaHelper::usersTable())->nullOnDelete();
            $table->unsignedBigInteger('from_account_id');
            $table->unsignedBigInteger('to_account_id');
            $table->decimal('amount', 15, 2);
            $table->date('transfer_date');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('from_account_id', 'acct_xfer_from_fk')
                ->references('id')->on('accounting_accounts')->restrictOnDelete();
            $table->foreign('to_account_id', 'acct_xfer_to_fk')
                ->references('id')->on('accounting_accounts')->restrictOnDelete();
            $table->foreign('journal_entry_id', 'acct_xfer_je_fk')
                ->references('id')->on('accounting_journal_entries')->nullOnDelete();
            TenancySchemaHelper::addOrgBranchIndex($table, 'acct_xfer_org_branch_idx');
            if (TenancySchemaHelper::usesBranchId()) {
                $table->index(['branch_id', 'transfer_date'], 'acct_xfer_branch_date_idx');
            } elseif (TenancySchemaHelper::usesOrganizationId()) {
                $table->index(['organization_id', 'transfer_date'], 'acct_xfer_org_date_idx');
            } else {
                $table->index('transfer_date', 'acct_xfer_date_idx');
            }
        });

        Schema::table('accounting_journal_entries', function (Blueprint $table) {
            $table->foreign('accounting_transfer_id', 'acct_je_xfer_fk')
                ->references('id')
                ->on('accounting_transfers')
                ->nullOnDelete();
        });

        if (class_exists(AccountingCoaSeeder::class) && TenancySchemaHelper::usesBranchId()) {
            $branchClass = config('accounting.models.branch', \App\Models\Branch::class);
            if (is_string($branchClass) && class_exists($branchClass)) {
                $seeder = app(AccountingCoaSeeder::class);
                $branchClass::query()->withoutGlobalScopes()
                    ->when(
                        Schema::hasColumn((new $branchClass)->getTable(), 'deleted_at'),
                        fn ($q) => $q->whereNull('deleted_at')
                    )
                    ->orderBy('id')
                    ->each(function ($branch) use ($seeder) {
                        $organizationId = TenancySchemaHelper::usesOrganizationId()
                            ? (int) $branch->organization_id
                            : 1;
                        $seeder->seedDefaultsForBranch(
                            $organizationId,
                            (int) $branch->id,
                            $branch->owner_user_id ? (int) $branch->owner_user_id : null
                        );
                    });
            }
        }
    }

    public function down(): void
    {
        Schema::table('accounting_journal_entries', function (Blueprint $table) {
            $table->dropForeign('acct_je_xfer_fk');
        });

        Schema::dropIfExists('accounting_transfers');
        Schema::dropIfExists('accounting_expense_category_mappings');
        Schema::dropIfExists('accounting_payment_method_mappings');
        Schema::dropIfExists('accounting_document_mappings');
        Schema::dropIfExists('accounting_transactions');
        Schema::dropIfExists('accounting_journal_entry_lines');
        Schema::dropIfExists('accounting_reconciles');
        Schema::dropIfExists('accounting_journal_entries');
        Schema::dropIfExists('accounting_branch_settings');
        Schema::dropIfExists('accounting_accounts');
    }
};