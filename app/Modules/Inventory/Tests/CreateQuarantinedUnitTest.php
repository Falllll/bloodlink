<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\BloodBatch;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Facility;
use App\Modules\Donor\Domain\Events\DonationCompleted;
use App\Modules\Inventory\Domain\BatchStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CreateQuarantinedUnitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Satu baris donations berikut janji temu walk-in yang sudah completed,
     * ditulis langsung seperti RecordDonation menulisnya -- tanpa event(),
     * supaya tiap test memutuskan sendiri kapan pendengarnya jalan.
     */
    private function donationRow(Donor $donor): Donation
    {
        // Ditulis lewat query builder: ModInventory tidak boleh menyentuh
        // AppointmentStatus (DomDonor) yang di-cast model Appointment.
        $appointmentId = DB::table('appointments')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'donor_id' => $donor->id,
            'facility_id' => $donor->registered_facility_id,
            'scheduled_for' => null,
            'status' => 'completed',
            'arrived_at' => now()->subHour(),
            'screened_at' => now()->subMinutes(40),
            'completed_at' => now()->subMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $donation = new Donation([
            'volume_ml' => 450,
            'started_at' => now()->subMinutes(15),
            'completed_at' => now()->subMinutes(5),
        ]);

        $donation->forceFill([
            'public_id' => (string) Str::uuid(),
            'donor_id' => $donor->id,
            'facility_id' => $donor->registered_facility_id,
            'appointment_id' => $appointmentId,
        ])->save();

        return $donation;
    }

    private function donor(): Donor
    {
        $facility = Facility::factory()->create();

        return Donor::factory()->create(['registered_facility_id' => $facility->id]);
    }

    public function test_a_completed_donation_creates_exactly_one_quarantined_unit(): void
    {
        $donor = $this->donor();
        $donation = $this->donationRow($donor);

        event(new DonationCompleted($donor->id, $donation->id));

        $this->assertSame(1, BloodBatch::query()->count());

        $unit = BloodBatch::query()->where('donation_id', $donation->id)->firstOrFail();

        $this->assertSame(BatchStatus::QUARANTINED, $unit->status);
        $this->assertSame('whole_blood', $unit->component);
        $this->assertSame($donor->id, $unit->donor_id);
        $this->assertNull($unit->expires_at);
    }

    public function test_dispatching_the_same_event_twice_creates_only_one_unit(): void
    {
        $donor = $this->donor();
        $donation = $this->donationRow($donor);

        event(new DonationCompleted($donor->id, $donation->id));
        event(new DonationCompleted($donor->id, $donation->id));

        $this->assertSame(1, BloodBatch::query()->count());
    }

    public function test_the_database_rejects_a_second_unit_for_the_same_donation(): void
    {
        $donor = $this->donor();
        $donation = $this->donationRow($donor);

        $row = fn (string $batchNumber): array => [
            'public_id' => (string) Str::uuid(),
            'batch_number' => $batchNumber,
            'donation_id' => $donation->id,
            'donor_id' => $donor->id,
            'facility_id' => $donation->facility_id,
            'component' => 'whole_blood',
            'volume_ml' => 450,
            'status' => 'quarantined',
            'collected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('blood_batches')->insert($row('RAW-1'));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('blood_batches_donation_id_unique');

        // Nomor batch berbeda: yang harus menolak adalah index donation_id.
        DB::table('blood_batches')->insert($row('RAW-2'));
    }

    public function test_the_unit_copies_facility_volume_and_collected_at_from_the_donation(): void
    {
        $donor = $this->donor();
        $donor->forceFill(['blood_group' => 'O', 'rh_factor' => 'negative'])->save();
        $donation = $this->donationRow($donor);

        event(new DonationCompleted($donor->id, $donation->id));

        $unit = BloodBatch::query()->where('donation_id', $donation->id)->firstOrFail();
        $donation->refresh();

        $this->assertSame($donation->facility_id, $unit->facility_id);
        $this->assertSame($donation->volume_ml, $unit->volume_ml);
        $this->assertTrue($donation->completed_at->equalTo($unit->collected_at));
        $this->assertSame('O', $unit->blood_group);
        $this->assertSame('negative', $unit->rh_factor);
    }
}
