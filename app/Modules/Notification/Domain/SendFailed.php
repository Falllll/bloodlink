<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

use RuntimeException;

/**
 * Transport menolak satu pesan. $failureCode berukuran kolom
 * notification_deliveries.failure_code; pesannya TIDAK memuat alamat maupun isi
 * pesan, karena exception ini berakhir di log dan failed_jobs.
 */
final class SendFailed extends RuntimeException
{
    private function __construct(public readonly string $failureCode, string $detail)
    {
        parent::__construct($detail);
    }

    public static function because(string $failureCode, string $detail): self
    {
        return new self(substr($failureCode, 0, 64), $detail);
    }
}
