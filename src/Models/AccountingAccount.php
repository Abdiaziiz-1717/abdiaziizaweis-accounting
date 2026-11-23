<?php

namespace Abdiaziizaweis\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Abdiaziizaweis\Accounting\Enums\AccountingAccountType;
use Abdiaziizaweis\Accounting\Models\Concerns\BelongsToAccountingContext;

class AccountingAccount extends Model
{
    use BelongsToAccountingContext;
    use SoftDeletes;

    protected $table = 'accounting_accounts';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'owner_user_id',
        'parent_id',
        'name',
        'number',
        'key_name',
        'account_type',
        'detail_type',
        'description',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'account_type' => AccountingAccountType::class,
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        $model = config('accounting.models.user', \App\Models\User::class);

        return $this->belongsTo($model, 'owner_user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}