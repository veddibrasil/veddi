<?php

namespace App\Services\Printer;

use App\Contracts\PrinterServiceInterface;
use App\Models\BranchPrinter;

class EscPosPrinterService implements PrinterServiceInterface
{
    private const CONNECT_TIMEOUT_SECONDS = 3;

    public function testConnection(BranchPrinter $printer): bool
    {
        $socket = @fsockopen($printer->ip_address, $printer->port, $errorCode, $errorMessage, self::CONNECT_TIMEOUT_SECONDS);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
