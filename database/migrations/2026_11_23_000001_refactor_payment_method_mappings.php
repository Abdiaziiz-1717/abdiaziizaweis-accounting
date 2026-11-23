<?php

use Abdiaziizaweis\Accounting\Database\Migration\TenancySchemaHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_payment_method_mappings', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_debit_account_id')->nullable()->change();
            $table->unsignedBigInteger('sales_credit_account_id')->nullable()->change();
        });

        Schema::create('accounting_payment_method_mapping_sides', function (Blueprint $table) {
            $table->id();
            TenancySchemaHelper::addOrganizationColumn($table);
            TenancySchemaHelper::addBranchColumn($table);
            $table->foreignId('payment_method_mapping_id')
                ->constrained('accounting_payment_method_mappings')
                ->cascadeOnDelete();
            $table->string('module', 64);
            $table->unsignedBigInteger('debit_account_id')->nullable();
            $table->unsignedBigInteger('credit_account_id')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->foreign('debit_account_id', 'acct_pm_side_debit_fk')
                ->references('id')->on('accounting_accounts')->restrictOnDelete();
            $table->foreign('credit_account_id', 'acct_pm_side_credit_fk')
                ->references('id')->on('accounting_accounts')->restrictOnDelete();
            $table->unique(['payment_method_mapping_id', 'module'], 'acct_pm_side_map_module_uq');
            TenancySchemaHelper::addOrganizationIndex($table);
        });

        $rows = DB::table('accounting_payment_method_mappings')->orderBy('id')->get();
        $now = now();

        $hasOrg = Schema::hasColumn('accounting_payment_method_mapping_sides', 'organization_id');
        $hasBranch = Schema::hasColumn('accounting_payment_method_mapping_sides', 'branch_id');

        foreach ($rows as $row) {
            if ($row->sales_debit_account_id || $row->sales_credit_account_id) {
                $this->insertSideRow([
                    'payment_method_mapping_id' => $row->id,
                    'module' => 'sales',
                    'debit_account_id' => $row->sales_debit_account_id,
                    'credit_account_id' => $row->sales_credit_account_id,
                    'is_enabled' => (bool) $row->is_enabled,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $row, $hasOrg, $hasBranch);
            }

            $purchaseDebit = $row->purchase_debit_account_id ?? null;
            $purchaseCredit = $row->purchase_credit_account_id ?? null;
            if ($purchaseDebit || $purchaseCredit) {
                $this->insertSideRow([
                    'payment_method_mapping_id' => $row->id,
                    'module' => 'purchase',
                    'debit_account_id' => $purchaseDebit,
                    'credit_account_id' => $purchaseCredit,
                    'is_enabled' => (bool) $row->is_enabled,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $row, $hasOrg, $hasBranch);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function insertSideRow(array $payload, object $parent, bool $hasOrg, bool $hasBranch): void
    {
        if ($hasOrg) {
            $payload['organization_id'] = $parent->organization_id;
        }
        if ($hasBranch) {
            $payload['branch_id'] = $parent->branch_id;
        }

        DB::table('accounting_payment_method_mapping_sides')->insert($payload);
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_payment_method_mapping_sides');

        Schema::table('accounting_payment_method_mappings', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_debit_account_id')->nullable(false)->change();
            $table->unsignedBigInteger('sales_credit_account_id')->nullable(false)->change();
        });
    }
};