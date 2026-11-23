<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional, branch-level accounts for splitting tax and discount out of the plain
 * 2-line invoice JE into their own lines (Maareeye-style VAT payable + sales/purchase
 * discount accounts). All nullable — when unset, invoice posting is unchanged
 * (single debit/credit pair via the existing document map). Deliberately NOT tied to
 * `accounting_document_mappings` since these are single accounts, not debit/credit pairs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_branch_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('tax_payable_account_id')->nullable()->after('enable_account_numbers');
            $table->unsignedBigInteger('tax_receivable_account_id')->nullable()->after('tax_payable_account_id');
            $table->unsignedBigInteger('sales_discount_account_id')->nullable()->after('tax_receivable_account_id');
            $table->unsignedBigInteger('purchase_discount_account_id')->nullable()->after('sales_discount_account_id');

            $table->foreign('tax_payable_account_id', 'acct_bs_tax_payable_fk')
                ->references('id')->on('accounting_accounts')->nullOnDelete();
            $table->foreign('tax_receivable_account_id', 'acct_bs_tax_receivable_fk')
                ->references('id')->on('accounting_accounts')->nullOnDelete();
            $table->foreign('sales_discount_account_id', 'acct_bs_sales_disc_fk')
                ->references('id')->on('accounting_accounts')->nullOnDelete();
            $table->foreign('purchase_discount_account_id', 'acct_bs_purch_disc_fk')
                ->references('id')->on('accounting_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('accounting_branch_settings', function (Blueprint $table) {
            $table->dropForeign('acct_bs_tax_payable_fk');
            $table->dropForeign('acct_bs_tax_receivable_fk');
            $table->dropForeign('acct_bs_sales_disc_fk');
            $table->dropForeign('acct_bs_purch_disc_fk');
            $table->dropColumn([
                'tax_payable_account_id',
                'tax_receivable_account_id',
                'sales_discount_account_id',
                'purchase_discount_account_id',
            ]);
        });
    }
};