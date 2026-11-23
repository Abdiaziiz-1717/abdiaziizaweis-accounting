<?php

namespace Abdiaziizaweis\Accounting\Contracts;

interface AccountingContextInterface
{
    public function getOrganizationId(): int;

    public function getBranchId(): ?int;

    public function getUserId(): int;
}