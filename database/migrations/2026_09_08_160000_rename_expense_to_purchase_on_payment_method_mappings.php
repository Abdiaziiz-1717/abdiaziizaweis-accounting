<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment-method mappings: the unused "expense" pair (retired when company
 * expenses moved to expense-category-only mapping) is repurposed as the
 * "purchase" pair — Maareeye-style: each payment mode has a Sales account
 * (money in) and a Purchase account (money out to suppliers), used for
 * `purchase_payment` transactions only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('accounting_payment_method_mappings', function (Blueprint $table) {
                $table->dropForeign('acct_pm_exp_debit_fk');
                $table->dropForeign('acct_pm_exp_credit_fk');
            });
        }

        Schema::table('accounting_payment_method_mappings', function (Blueprint $table) {
            $table->renameColumn('expense_debit_account_id', 'purchase_debit_account_id');
            $table->renameColumn('expense_credit_account_id', 'purchase_credit_account_id');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('accounting_payment_method_mappings', function (Blueprint $table) {
                $table->foreign('purchase_debit_account_id', 'acct_pm_purch_debit_fk')
                    ->references('id')->on('accounting_accounts')->restrictOnDelete();
                $table->foreign('purchase_credit_account_id', 'acct_pm_purch_credit_fk')
                    ->references('id')->on('accounting_accounts')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('accounting_payment_method_mappings', function (Blueprint $table) {
            $table->dropForeign('acct_pm_purch_debit_fk');
            $table->dropForeign('acct_pm_purch_credit_fk');
        });

        Schema::table('accounting_payment_method_mappings', function (Blueprint $table) {
            $table->renameColumn('purchase_debit_account_id', 'expense_debit_account_id');
            $table->renameColumn('purchase_credit_account_id', 'expense_credit_account_id');
        });

        Schema::table('accounting_payment_method_mappings', function (Blueprint $table) {
            $table->foreign('expense_debit_account_id', 'acct_pm_exp_debit_fk')
                ->references('id')->on('accounting_accounts')->restrictOnDelete();
            $table->foreign('expense_credit_account_id', 'acct_pm_exp_credit_fk')
                ->references('id')->on('accounting_accounts')->restrictOnDelete();
        });
    }
};