<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Inventory\Domain\TransferStatus;
use App\Modules\Request\Application\AllocateUnitsFefo;
use App\Modules\Request\Application\IssueAllocatedUnit;
use App\Modules\Request\Application\ReturnAllocatedUnit;
use PHPUnit\Framework\TestCase;

/**
 * AllocateUnitsFefo menulis status Inventory sebagai literal karena modul Request
 * tidak boleh mengimpor domain Inventory (deptrac). Test ini -- di luar paths
 * deptrac -- yang menjaga literal itu tetap sama dengan enum aslinya.
 */
final class RequestInventoryLiteralsTest extends TestCase
{
    public function test_the_released_literal_matches_batch_status(): void
    {
        $this->assertSame(BatchStatus::RELEASED->value, AllocateUnitsFefo::RELEASED);
    }

    public function test_the_reserved_literal_matches_batch_status(): void
    {
        $this->assertSame(BatchStatus::RESERVED->value, IssueAllocatedUnit::RESERVED);
    }

    public function test_the_issued_literal_matches_batch_status(): void
    {
        $this->assertSame(BatchStatus::ISSUED->value, ReturnAllocatedUnit::ISSUED);
    }

    public function test_the_open_transfer_literals_match_transfer_status(): void
    {
        $open = array_values(array_map(
            fn (TransferStatus $status): string => $status->value,
            array_filter(TransferStatus::cases(), fn (TransferStatus $status): bool => $status->isOpen()),
        ));

        $this->assertSame($open, AllocateUnitsFefo::OPEN_TRANSFER_STATUSES);
    }
}
