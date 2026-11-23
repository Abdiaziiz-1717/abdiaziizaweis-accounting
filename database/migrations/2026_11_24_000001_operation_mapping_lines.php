<?php

use Abdiaziizaweis\Accounting\Database\Migration\TenancySchemaHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2: N-line operation mappings. Hosts configure lines; bootstrap does not seed them.
 * Copies any existing 2-line document maps into operation lines (amount source = amount).
 * Drops branch-setting columns that existed only for automatic tax/discount splits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_operation_mappings', function (Blueprint $table) {
            $table->id();
            TenancySchemaHelper::addOrganizationColumn($table);
            TenancySchemaHelper::addBranchColumn($table);
            $table->string('operation_key', 120);
            $table->string('label')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();

            if (TenancySchemaHelper::usesBranchId()) {
                $table->unique(['branch_id', 'operation_key'], 'acct_op_map_branch_key_uq');
            } elseif (TenancySchemaHelper::usesOrganizationId()) {
                $table->unique(['organization_id', 'operation_key'], 'acct_op_map_org_key_uq');
            } else {
                $table->unique('operation_key', 'acct_op_map_key_uq');
            }
        });

        Schema::create('accounting_operation_mapping_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operation_mapping_id');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('account_key', 80)->nullable();
            $table->string('side', 8);
            $table->string('amount_source', 64);
            $table->boolean('skip_when_zero')->default(false);
            $table->timestamps();

            $table->foreign('operation_mapping_id', 'acct_op_line_map_fk')
                ->references('id')->on('accounting_operation_mappings')->cascadeOnDelete();
            $table->foreign('account_id', 'acct_op_line_acct_fk')
                ->references('id')->on('accounting_accounts')->restrictOnDelete();
            $table->index(['operation_mapping_id', 'sort_order'], 'acct_op_line_sort_idx');
        });

        if (Schema::hasTable('accounting_transactions') && ! Schema::hasColumn('accounting_transactions', 'payload')) {
            Schema::table('accounting_transactions', function (Blueprint $table) {
                $table->json('payload')->nullable();
            });
        }

        if (Schema::hasTable('accounting_document_mappings')) {
            $rows = DB::table('accounting_document_mappings')->orderBy('id')->get();
            foreach ($rows as $row) {
                $key = trim((string) $row->module).'.'.trim((string) $row->document_type);
                $parent = [
                    'operation_key' => $key,
                    'label' => $key,
                    'is_enabled' => (bool) $row->is_enabled,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (Schema::hasColumn('accounting_operation_mappings', 'organization_id') && isset($row->organization_id)) {
                    $parent['organization_id'] = $row->organization_id;
                }
                if (Schema::hasColumn('accounting_operation_mappings', 'branch_id') && isset($row->branch_id)) {
                    $parent['branch_id'] = $row->branch_id;
                }

                $id = DB::table('accounting_operation_mappings')->insertGetId($parent);
                $now = now();
                if (! empty($row->debit_account_id)) {
                    DB::table('accounting_operation_mapping_lines')->insert([
                        'operation_mapping_id' => $id,
                        'sort_order' => 0,
                        'account_id' => $row->debit_account_id,
                        'account_key' => null,
                        'side' => 'debit',
                        'amount_source' => 'amount',
                        'skip_when_zero' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                if (! empty($row->credit_account_id)) {
                    DB::table('accounting_operation_mapping_lines')->insert([
                        'operation_mapping_id' => $id,
                        'sort_order' => 1,
                        'account_id' => $row->credit_account_id,
                        'account_key' => null,
                        'side' => 'credit',
                        'amount_source' => 'amount',
                        'skip_when_zero' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        if (
            Schema::getConnection()->getDriverName() !== 'sqlite'
            && Schema::hasTable('accounting_branch_settings')
            && Schema::hasColumn('accounting_branch_settings', 'tax_payable_account_id')
        ) {
            Schema::table('accounting_branch_settings', function (Blueprint $table) {
                foreach ([
                    'acct_bs_tax_payable_fk',
                    'acct_bs_tax_receivable_fk',
                    'acct_bs_sales_disc_fk',
                    'acct_bs_purch_disc_fk',
                ] as $fk) {
                    try {
                        $table->dropForeign($fk);
                    } catch (\Throwable) {
                        // Constraint already absent (partial migrate or sqlite).
                    }
                }
            });

            Schema::table('accounting_branch_settings', function (Blueprint $table) {
                $drop = [];
                foreach ([
                    'tax_payable_account_id',
                    'tax_receivable_account_id',
                    'sales_discount_account_id',
                    'purchase_discount_account_id',
                ] as $column) {
                    if (Schema::hasColumn('accounting_branch_settings', $column)) {
                        $drop[] = $column;
                    }
                }
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_operation_mapping_lines');
        Schema::dropIfExists('accounting_operation_mappings');

        if (Schema::hasColumn('accounting_transactions', 'payload')) {
            Schema::table('accounting_transactions', function (Blueprint $table) {
                $table->dropColumn('payload');
            });
        }
    }
};
