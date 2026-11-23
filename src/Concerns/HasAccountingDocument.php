<?php

namespace Abdiaziizaweis\Accounting\Concerns;

use Abdiaziizaweis\Accounting\Contracts\AccountingDocumentInterface;
use Abdiaziizaweis\Accounting\Services\AccountingDocumentBridge;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Keeps accounting transactions in sync when the host model implements
 * {@see AccountingDocumentInterface}.
 *
 * @mixin Model
 */
trait HasAccountingDocument
{
    public static function bootHasAccountingDocument(): void
    {
        $implements = class_implements(static::class) ?: [];
        if (! isset($implements[AccountingDocumentInterface::class])) {
            throw new LogicException(
                static::class.' must implement '.AccountingDocumentInterface::class.' to use HasAccountingDocument.'
            );
        }

        static::deleting(function (Model $model): void {
            if (! $model instanceof AccountingDocumentInterface) {
                return;
            }

            app(AccountingDocumentBridge::class)->voidFor(
                $model,
                true,
                'Host document deleted'
            );
        });

        static::saved(function (Model $model): void {
            if (! $model instanceof AccountingDocumentInterface) {
                return;
            }

            app(AccountingDocumentBridge::class)->record($model);
        });
    }
}