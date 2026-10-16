<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\BloodBatch;
use App\Modules\Inventory\Application\Exceptions\BloodBatchTransitionRejected;
use App\Modules\Inventory\Application\TransitionBloodBatch;
use App\Modules\Inventory\Domain\BatchStatus;
use Database\Seeders\ComponentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gerbang penerbitan (Kartu 350): perpindahan status biasa tidak pernah bisa
 * mencapai ISSUED, dari status mana pun yang grafnya mengizinkan. Jalur sahnya
 * hanya IssueAllocatedUnit di modul Request (CrossmatchTest).
 */
final class IssueGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ComponentTypeSeeder::class);
    }

    private function unitIn(BatchStatus $status): BloodBatch
    {
        $unit = BloodBatch::factory()->create(['component' => 'packed_red_cells']);
        $unit->forceFill(['status' => $status, 'released_at' => now()])->save();

        return $unit;
    }

    public function test_the_plain_transition_path_can_never_issue(): void
    {
        // Graf mengizinkan RELEASED -> ISSUED dan RESERVED -> ISSUED; gerbangnya menolak keduanya.
        foreach ([BatchStatus::RELEASED, BatchStatus::RESERVED] as $from) {
            $this->assertTrue($from->canTransitionTo(BatchStatus::ISSUED));
            $unit = $this->unitIn($from);

            try {
                app(TransitionBloodBatch::class)->handle($unit, BatchStatus::ISSUED);
                $this->fail("TransitionBloodBatch must refuse ISSUED from {$from->value}.");
            } catch (BloodBatchTransitionRejected $e) {
                $this->assertStringContainsString('crossmatch-gated', $e->getMessage());
            }

            $this->assertSame($from, $unit->fresh()?->status);
        }
    }
}
