<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jtech\Accounting\Database\Migration\TenancySchemaHelper;

/**
 * Accounting source-of-truth: void lifecycle instead of soft/hard delete
 * on transactions and journal entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_journal_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('accounting_journal_entries', 'status')) {
                $table->string('status', 20)->default('active')->after('amount');
            }
            if (! Schema::hasColumn('accounting_journal_entries', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('accounting_journal_entries', 'voided_by_user_id')) {
                $table->foreignId('voided_by_user_id')
                    ->nullable()
                    ->after('voided_at')
                    ->constrained(TenancySchemaHelper::usersTable())
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('accounting_journal_entries', 'void_reason')) {
                $table->string('void_reason', 255)->nullable()->after('voided_by_user_id');
            }
        });

        // Soft-deleted JEs → voided (keep rows + lines).
        if (Schema::hasColumn('accounting_journal_entries', 'deleted_at')) {
            DB::table('accounting_journal_entries')
                ->whereNotNull('deleted_at')
                ->update([
                    'status' => 'voided',
                    'voided_at' => DB::raw('COALESCE(voided_at, deleted_at)'),
                    'void_reason' => DB::raw("COALESCE(void_reason, 'Migrated from soft-delete')"),
                ]);

            Schema::table('accounting_journal_entries', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        Schema::table('accounting_journal_entries', function (Blueprint $table) {
            if (TenancySchemaHelper::usesBranchId()) {
                $table->index(['branch_id', 'status'], 'acct_je_branch_status_idx');
            } elseif (TenancySchemaHelper::usesOrganizationId()) {
                $table->index(['organization_id', 'status'], 'acct_je_org_status_idx');
            } else {
                $table->index('status', 'acct_je_status_idx');
            }
        });

        Schema::table('accounting_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('accounting_transactions', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('journal_entry_id');
            }
            if (! Schema::hasColumn('accounting_transactions', 'voided_by_user_id')) {
                $table->foreignId('voided_by_user_id')
                    ->nullable()
                    ->after('voided_at')
                    ->constrained(TenancySchemaHelper::usersTable())
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('accounting_transactions', 'void_reason')) {
                $table->string('void_reason', 255)->nullable()->after('voided_by_user_id');
            }
            if (! Schema::hasColumn('accounting_transactions', 'superseded_by_transaction_id')) {
                $table->unsignedBigInteger('superseded_by_transaction_id')->nullable()->after('void_reason');
            }
        });

        if (Schema::hasColumn('accounting_transactions', 'superseded_by_transaction_id')) {
            Schema::table('accounting_transactions', function (Blueprint $table) {
                $table->foreign('superseded_by_transaction_id', 'acct_tx_superseded_by_fk')
                    ->references('id')
                    ->on('accounting_transactions')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasColumn('accounting_transactions', 'deleted_at')) {
            DB::table('accounting_transactions')
                ->whereNotNull('deleted_at')
                ->update([
                    'status' => 'voided',
                    'voided_at' => DB::raw('COALESCE(voided_at, deleted_at)'),
                    'void_reason' => DB::raw("COALESCE(void_reason, 'Migrated from soft-delete')"),
                ]);

            Schema::table('accounting_transactions', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::table('accounting_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('accounting_transactions', 'superseded_by_transaction_id')) {
                $table->dropForeign('acct_tx_superseded_by_fk');
                $table->dropColumn('superseded_by_transaction_id');
            }
            foreach (['void_reason', 'voided_by_user_id', 'voided_at'] as $col) {
                if (Schema::hasColumn('accounting_transactions', $col)) {
                    if ($col === 'voided_by_user_id') {
                        $table->dropConstrainedForeignId('voided_by_user_id');
                    } else {
                        $table->dropColumn($col);
                    }
                }
            }
            if (! Schema::hasColumn('accounting_transactions', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('accounting_journal_entries', function (Blueprint $table) {
            foreach (['acct_je_branch_status_idx', 'acct_je_org_status_idx', 'acct_je_status_idx'] as $index) {
                if (Schema::hasIndex('accounting_journal_entries', $index)) {
                    $table->dropIndex($index);
                }
            }
            foreach (['void_reason', 'voided_by_user_id', 'voided_at', 'status'] as $col) {
                if (Schema::hasColumn('accounting_journal_entries', $col)) {
                    if ($col === 'voided_by_user_id') {
                        $table->dropConstrainedForeignId('voided_by_user_id');
                    } else {
                        $table->dropColumn($col);
                    }
                }
            }
            if (! Schema::hasColumn('accounting_journal_entries', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }
};