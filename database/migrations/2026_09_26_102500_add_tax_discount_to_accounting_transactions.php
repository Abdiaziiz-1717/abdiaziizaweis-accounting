<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot tax/discount on accounting transactions so economic edits
 * (including total-unchanged tax/discount/coupon rebalance) void+replace the tx.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('accounting_transactions', 'tax_amount')) {
                $table->decimal('tax_amount', 15, 2)->default(0)->after('amount');
            }
            if (! Schema::hasColumn('accounting_transactions', 'discount_amount')) {
                $table->decimal('discount_amount', 15, 2)->default(0)->after('tax_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounting_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('accounting_transactions', 'discount_amount')) {
                $table->dropColumn('discount_amount');
            }
            if (Schema::hasColumn('accounting_transactions', 'tax_amount')) {
                $table->dropColumn('tax_amount');
            }
        });
    }
};