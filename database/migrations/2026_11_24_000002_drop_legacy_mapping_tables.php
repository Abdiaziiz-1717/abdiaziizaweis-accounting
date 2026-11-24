<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Removes mapping tables that posting no longer uses.
 * Safe on fresh installs where those tables were never created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('accounting_payment_method_mapping_sides');
        Schema::dropIfExists('accounting_payment_method_mappings');
        Schema::dropIfExists('accounting_expense_category_mappings');
        Schema::dropIfExists('accounting_document_mappings');
    }

    public function down(): void
    {
        // Not restored. Operation mappings are the only mapping storage.
    }
};
