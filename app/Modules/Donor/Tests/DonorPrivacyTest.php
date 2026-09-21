<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Models\AuditLog;
use App\Models\Donor;
use App\Models\DonorConsent;
use App\Modules\Donor\Application\RecordDonorConsent;
use App\Modules\Donor\Domain\ConsentPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DonorPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_is_stored_as_ciphertext_in_the_database(): void
    {
        $donor = Donor::factory()->create([
            'phone' => '081234567890',
            'address' => 'Jl. Merdeka No. 1',
        ]);

        // Lewat query builder mentah: Eloquent mendekripsi otomatis.
        $raw = DB::table('donors')->where('id', $donor->id)->first();

        $this->assertNotSame('081234567890', $raw->phone);
        $this->assertNotSame('Jl. Merdeka No. 1', $raw->address);
        $this->assertStringNotContainsString('081234567890', $raw->phone);

        $fresh = Donor::findOrFail($donor->id);
        $this->assertSame('081234567890', $fresh->phone);
        $this->assertSame('Jl. Merdeka No. 1', $fresh->address);
    }

    public function test_the_same_number_written_two_ways_hashes_identically(): void
    {
        $a = Donor::phoneHash('0812-3456-7890');
        $b = Donor::phoneHash('081234567890');
        $c = Donor::phoneHash('+6281234567890');

        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
        $this->assertNotSame($a, Donor::phoneHash('081234567891'));

        $donor = Donor::factory()->create(['phone' => '0812-3456-7890']);
        $this->assertSame($a, DB::table('donors')->where('id', $donor->id)->value('phone_hash'));
    }

    public function test_granting_consent_revokes_the_previous_one(): void
    {
        $donor = Donor::factory()->create();
        $service = new RecordDonorConsent;

        $first = $service->grant($donor, [ConsentPurpose::DONATION], null, '127.0.0.1');
        $second = $service->grant($donor, [ConsentPurpose::DONATION, ConsentPurpose::DATA_RETENTION], null, null);

        $this->assertNotNull($first->fresh()->revoked_at);
        $this->assertTrue($second->fresh()->isActive());
        $this->assertSame(1, DonorConsent::where('donor_id', $donor->id)->whereNull('revoked_at')->count());
        $this->assertSame(2, DonorConsent::where('donor_id', $donor->id)->count());
        $this->assertSame($donor->registered_facility_id, $second->facility_id);
        $this->assertSame(config('privacy.consent.questionnaire_version'), $second->questionnaire_version);
    }

    public function test_the_audit_log_does_not_contain_the_address(): void
    {
        $donor = Donor::factory()->create([
            'phone' => '081234567890',
            'address' => 'Alamat Lama 123',
        ]);

        $donor->update(['address' => 'Alamat Baru 456']);

        $dump = AuditLog::where('auditable_type', Donor::class)
            ->where('auditable_id', $donor->id)
            ->get()
            ->map(fn (AuditLog $log) => json_encode($log->changes))
            ->implode(' ');

        $this->assertNotSame('', $dump);
        $this->assertStringNotContainsString('Alamat Lama 123', $dump);
        $this->assertStringNotContainsString('Alamat Baru 456', $dump);
        $this->assertStringNotContainsString('081234567890', $dump);
        $this->assertStringNotContainsString('phone_hash', $dump);
        $this->assertStringNotContainsString('eyJpdiI6', $dump); // awalan ciphertext Laravel
    }

    public function test_purge_refuses_to_run_without_a_retention_setting(): void
    {
        config(['privacy.retention.health_years' => null]);

        $this->artisan('privacy:purge-expired', ['--force' => true])
            ->expectsOutputToContain('PRIVACY_RETENTION_HEALTH_YEARS belum diisi')
            ->assertFailed();
    }

    public function test_purge_only_clears_expired_donors_without_retention_consent_and_only_with_force(): void
    {
        config(['privacy.retention.health_years' => 5]);

        $expired = Donor::factory()->create([
            'last_donation_date' => now()->subYears(6),
        ]);
        $expired->forceFill(['blood_group' => 'A', 'rh_factor' => 'positive', 'weight_kg' => 60])->save();

        $protected = Donor::factory()->create(['last_donation_date' => now()->subYears(6)]);
        $protected->forceFill(['blood_group' => 'B', 'rh_factor' => 'negative', 'weight_kg' => 70])->save();
        (new RecordDonorConsent)->grant($protected, [ConsentPurpose::DATA_RETENTION], null, null);

        $recent = Donor::factory()->create(['last_donation_date' => now()->subYear()]);
        $recent->forceFill(['blood_group' => 'O', 'rh_factor' => 'positive', 'weight_kg' => 55])->save();

        $this->artisan('privacy:purge-expired')->expectsOutputToContain('Dry run: 1')->assertSuccessful();
        $this->assertSame('A', $expired->fresh()->blood_group);

        $this->artisan('privacy:purge-expired', ['--force' => true])->assertSuccessful();

        $this->assertNull($expired->fresh()->blood_group);
        $this->assertNull($expired->fresh()->weight_kg);
        $this->assertNotNull(Donor::find($expired->id)); // barisnya tidak dihapus
        $this->assertSame('B', $protected->fresh()->blood_group);
        $this->assertSame('O', $recent->fresh()->blood_group);
    }
}
