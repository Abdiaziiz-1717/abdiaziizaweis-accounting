<?php

namespace Abdiaziizaweis\Accounting\Concerns;

/**
 * Default optional {@see \Abdiaziizaweis\Accounting\Contracts\AccountingDocumentInterface}
 * helpers. Applied by {@see HasAccountingDocument}.
 */
trait HasDefaultDocumentMethods
{
    public function getPaymentMethodId(): ?int
    {
        return null;
    }

    public function getExpenseCategoryId(): ?int
    {
        return null;
    }

    public function getOwnerUserId(): ?int
    {
        return null;
    }

    public function getPaymentSide(): string
    {
        return $this->getDocumentModule();
    }
}