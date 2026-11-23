<?php

namespace Abdiaziizaweis\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingOperationMappingLine extends Model
{
    protected $table = 'accounting_operation_mapping_lines';

    protected $fillable = [
        'operation_mapping_id',
        'sort_order',
        'account_id',
        'account_key',
        'side',
        'amount_source',
        'skip_when_zero',
    ];

    protected $casts = [
        'skip_when_zero' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(AccountingOperationMapping::class, 'operation_mapping_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'account_id');
    }
}
