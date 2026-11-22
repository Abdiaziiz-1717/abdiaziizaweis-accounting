<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the bank-reconciliation feature entirely (idempotent).
 *
 * Drops:
 *  - accounting_reconcile_id  FK + column from accounting_journal_entry_lines
 *  - is_reconciled            column from accounting_journal_entry_lines
 *  - accounting_reconciles    table
 *  - 3 Spatie permissions     (view|create|edit accounting reconciles)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1.  JE-lines table ────────────────────────────────────────────────
        // We need to be idempotent: previous failed runs may have partially
        // committed DDL (MySQL auto-commits each ALTER TABLE).

        $hasRecId = Schema::hasColumn('accounting_journal_entry_lines', 'accounting_reconcile_id');
        $hasIsRecon = Schema::hasColumn('accounting_journal_entry_lines', 'is_reconciled');
        $hasReconIdx = $this->indexExists('accounting_journal_entry_lines', 'acct_jel_acct_recon_idx');
        $hasRecIdx = $this->indexExists('accounting_journal_entry_lines', 'acct_jel_rec_idx');
        $hasAcctIdx = $this->indexExists('accounting_journal_entry_lines', 'acct_jel_account_idx');

        // Add a plain account_id index first so the FK acct_jel_account_fk keeps its
        // backing index after we drop the composite (account_id, is_reconciled) index.
        if (! $hasAcctIdx) {
            Schema::table('accounting_journal_entry_lines', function (Blueprint $table) {
                $table->index('account_id', 'acct_jel_account_idx');
            });
        }

        if ($hasReconIdx || $hasRecIdx || $hasRecId || $hasIsRecon) {
            Schema::table('accounting_journal_entry_lines', function (Blueprint $table) use (
                $hasReconIdx, $hasRecIdx, $hasRecId, $hasIsRecon
            ) {
                if ($hasReconIdx) {
                    $table->dropIndex('acct_jel_acct_recon_idx');
                }
                if ($hasRecIdx) {
                    $table->dropIndex('acct_jel_rec_idx');
                }

                $dropCols = [];
                if ($hasRecId) {
                    $dropCols[] = 'accounting_reconcile_id';
                }
                if ($hasIsRecon) {
                    $dropCols[] = 'is_reconciled';
                }
                if ($dropCols) {
                    $table->dropColumn($dropCols);
                }
            });
        }

        // ── 2.  Drop the reconciles table ─────────────────────────────────────
        Schema::dropIfExists('accounting_reconciles');

        // ── 3.  Remove the 3 reconcile Spatie permissions ─────────────────────
        $permNames = [
            'view accounting reconciles',
            'create accounting reconciles',
            'edit accounting reconciles',
        ];
        $permIds = DB::table('permissions')
            ->whereIn('name', $permNames)
            ->pluck('id');

        if ($permIds->isNotEmpty()) {
            foreach (['role_has_permissions', 'model_has_permissions', 'saas_module_permissions'] as $pivot) {
                if (Schema::hasTable($pivot)) {
                    DB::table($pivot)->whereIn('permission_id', $permIds)->delete();
                }
            }
            DB::table('permissions')->whereIn('id', $permIds)->delete();
        }
    }

    public function down(): void
    {
        // Restore accounting_reconciles table
        Schema::create('accounting_reconciles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->unsignedBigInteger('account_id')->index();
            $table->decimal('beginning_balance', 15, 2)->default(0);
            $table->decimal('ending_balance', 15, 2)->default(0);
            $table->date('ending_date');
            $table->string('status', 20)->default('in_progress');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Restore columns on journal entry lines
        Schema::table('accounting_journal_entry_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('accounting_reconcile_id')->nullable();
            $table->boolean('is_reconciled')->default(false);
            $table->foreign('accounting_reconcile_id', 'acct_jel_rec_fk')
                ->references('id')->on('accounting_reconciles')->nullOnDelete();
            $table->index(['account_id', 'is_reconciled'], 'acct_jel_acct_recon_idx');
            $table->index('accounting_reconcile_id', 'acct_jel_rec_idx');
            // Remove the plain account_id index we added (composite is restored)
            $table->dropIndex('acct_jel_account_idx');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $rows = DB::select('
            SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
            LIMIT 1
        ', [$table, $indexName]);

        return ! empty($rows);
    }
};