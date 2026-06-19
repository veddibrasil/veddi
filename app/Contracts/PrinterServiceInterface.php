<?php

namespace App\Contracts;

use App\Models\BranchPrinter;

interface PrinterServiceInterface
{
    public function testConnection(BranchPrinter $printer): bool;
}
