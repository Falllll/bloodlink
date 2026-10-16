<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain\Events;

/**
 * Unit yang dialokasikan lolos gerbang crossmatch dan diterbitkan untuk pasien.
 * Dipancarkan HANYA oleh IssueAllocatedUnit, setelah crossmatch terbaru terbukti
 * compatible. Didengar SINKRON oleh modul Inventory, pemilik status unit, di
 * transaksi penerbitan yang sama.
 */
final class AllocatedUnitIssued
{
    public function __construct(
        public readonly int $bloodBatchId,
    ) {}
}
