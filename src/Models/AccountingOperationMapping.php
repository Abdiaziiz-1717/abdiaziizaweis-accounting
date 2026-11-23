<?php

namespace Abdiaziizaweis\Accounting\Models;

use Abdiaziizaweis\Accounting\Models\Concerns\BelongsToAccountingContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingOperationMapping extends Model
{
    use BelongsToAccountingContext;

    protected $table = 'accounting_operation_mappings';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'operation_key',
        'label',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(AccountingOperationMappingLine::class, 'operation_mapping_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
