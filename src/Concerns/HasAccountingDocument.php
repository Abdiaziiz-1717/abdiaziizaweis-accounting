<?php

namespace Abdiaziizaweis\Accounting\Concerns;

use Abdiaziizaweis\Accounting\Contracts\AccountingDocumentInterface;
use Abdiaziizaweis\Accounting\Services\AccountingDocumentBridge;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Automatically syncs accounting when a host Eloquent model implements
 * {@see AccountingDocumentInterface}.
 *
 * **Requirements**
 * - The using class must implement {@see AccountingDocumentInterface}.
 * - HTTP or CLI context must resolve {@see \Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface}
 *   (org/branch) when {@see AccountingDocumentBridge::record()} runs.
 *
 * **Behavior**
 * - On {@see Model::deleting}: calls {@see AccountingDocumentBridge::voidFor()} with `strict: true`
 *   so void failures block deletion.
 * - On {@see Model::saved}: calls {@see AccountingDocumentBridge::record()} after create and update
 *   (economic changes on existing txs are handled inside the transaction service).
 *
 * @mixin Model&AccountingDocumentInterface
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