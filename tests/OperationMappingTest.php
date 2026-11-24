<?php

namespace Abdiaziizaweis\Accounting\Tests;

use Abdiaziizaweis\Accounting\Models\AccountingAccount;
use Abdiaziizaweis\Accounting\Models\AccountingJournalEntry;
use Abdiaziizaweis\Accounting\Models\AccountingOperationMapping;
use Abdiaziizaweis\Accounting\Models\AccountingOperationMappingLine;
use Abdiaziizaweis\Accounting\Models\AccountingTransaction;
use Abdiaziizaweis\Accounting\Services\AccountingDocumentBridge;
use Abdiaziizaweis\Accounting\Services\AccountingMappingSeeder;
use Abdiaziizaweis\Accounting\Services\OperationMappingService;
use Abdiaziizaweis\Accounting\Tests\Support\FakeDocument;

class OperationMappingTest extends TestCase
{
    public function test_bootstrap_seeds_accounts_and_no_document_mappings(): void
    {
        app(AccountingMappingSeeder::class)->seedDefaultsForBranch(0, 0);

        $this->assertGreaterThan(10, AccountingAccount::withoutGlobalScopes()->count());
        $this->assertSame(0, AccountingOperationMapping::withoutGlobalScopes()->count());
    }

    public function test_unmapped_operation_does_not_create_a_journal(): void
    {
        app(AccountingMappingSeeder::class)->seedDefaultsForBranch(0, 0);

        app(AccountingDocumentBridge::class)->record(new FakeDocument('sale.recorded', [
            'amount' => 100,
            'cost' => 40,
        ]));

        $tx = AccountingTransaction::withoutGlobalScopes()->first();
        $this->assertNotNull($tx);
        $this->assertSame('not_mapped', $tx->status->value);
        $this->assertNull($tx->journal_entry_id);
        $this->assertSame(0, AccountingJournalEntry::withoutGlobalScopes()->count());
    }

    public function test_four_line_sale_mapping_uses_payload_cost_and_balances(): void
    {
        app(AccountingMappingSeeder::class)->seedDefaultsForBranch(0, 0);

        app(OperationMappingService::class)->replace('sale.recorded', [
            ['account_key' => 'accounts_receivable', 'side' => 'debit', 'amount_source' => 'amount'],
            ['account_key' => 'sales_revenue', 'side' => 'credit', 'amount_source' => 'amount'],
            ['account_key' => 'cost_of_sales', 'side' => 'debit', 'amount_source' => 'cost', 'skip_when_zero' => true],
            ['account_key' => 'inventory_asset', 'side' => 'credit', 'amount_source' => 'cost', 'skip_when_zero' => true],
        ], 'Sale recorded', true);

        app(AccountingDocumentBridge::class)->record(new FakeDocument('sale.recorded', [
            'amount' => 100,
            'cost' => 40,
        ]));

        $tx = AccountingTransaction::withoutGlobalScopes()->first();
        $this->assertSame('mapped', $tx->status->value);

        $lines = $tx->journalEntry->lines;
        $this->assertCount(4, $lines);
        $debits = round((float) $lines->sum('debit'), 2);
        $credits = round((float) $lines->sum('credit'), 2);
        $this->assertEquals(140.0, $debits);
        $this->assertEquals(140.0, $credits);

        $cogsId = AccountingAccount::withoutGlobalScopes()->where('key_name', 'cost_of_sales')->value('id');
        $cogs = $lines->firstWhere('account_id', $cogsId);
        $this->assertEquals(40.0, (float) $cogs->debit);
    }

    public function test_tax_and_discount_are_ordinary_payload_keys(): void
    {
        app(AccountingMappingSeeder::class)->seedDefaultsForBranch(0, 0);

        app(OperationMappingService::class)->replace('sale.recorded', [
            ['account_key' => 'accounts_receivable', 'side' => 'debit', 'amount_source' => 'amount'],
            ['account_key' => 'sales_revenue', 'side' => 'credit', 'amount_source' => 'amount'],
            ['account_key' => 'sales_discounts', 'side' => 'debit', 'amount_source' => 'discount_amount', 'skip_when_zero' => true],
            ['account_key' => 'sales_revenue', 'side' => 'credit', 'amount_source' => 'discount_amount', 'skip_when_zero' => true],
            ['account_key' => 'tax_receivable', 'side' => 'debit', 'amount_source' => 'tax_amount', 'skip_when_zero' => true],
            ['account_key' => 'tax_payable', 'side' => 'credit', 'amount_source' => 'tax_amount', 'skip_when_zero' => true],
        ]);

        app(AccountingDocumentBridge::class)->record(new FakeDocument('sale.recorded', [
            'amount' => 80,
            'tax_amount' => 5,
            'discount_amount' => 2,
        ], 2));

        $tx = AccountingTransaction::withoutGlobalScopes()->where('transactionable_id', 2)->first();
        $this->assertSame('mapped', $tx->status->value);
        $this->assertEquals(87.0, round((float) $tx->journalEntry->lines->sum('debit'), 2));
        $this->assertEquals(87.0, round((float) $tx->journalEntry->lines->sum('credit'), 2));
    }

    public function test_unbalanced_mapping_does_not_post(): void
    {
        app(AccountingMappingSeeder::class)->seedDefaultsForBranch(0, 0);

        $ar = AccountingAccount::withoutGlobalScopes()->where('key_name', 'accounts_receivable')->first();
        $revenue = AccountingAccount::withoutGlobalScopes()->where('key_name', 'sales_revenue')->first();

        $mapping = AccountingOperationMapping::withoutGlobalScopes()->create([
            'operation_key' => 'sale.recorded',
            'label' => 'Broken',
            'is_enabled' => true,
        ]);

        AccountingOperationMappingLine::query()->create([
            'operation_mapping_id' => $mapping->id,
            'sort_order' => 0,
            'account_id' => $ar->id,
            'account_key' => 'accounts_receivable',
            'side' => 'debit',
            'amount_source' => 'amount',
            'skip_when_zero' => false,
        ]);
        AccountingOperationMappingLine::query()->create([
            'operation_mapping_id' => $mapping->id,
            'sort_order' => 1,
            'account_id' => $revenue->id,
            'account_key' => 'sales_revenue',
            'side' => 'debit',
            'amount_source' => 'amount',
            'skip_when_zero' => false,
        ]);

        app(AccountingDocumentBridge::class)->record(new FakeDocument('sale.recorded', [
            'amount' => 50,
        ], 3));

        $tx = AccountingTransaction::withoutGlobalScopes()->where('transactionable_id', 3)->first();
        $this->assertSame('not_mapped', $tx->status->value);
        $this->assertNull($tx->journal_entry_id);
    }
}
