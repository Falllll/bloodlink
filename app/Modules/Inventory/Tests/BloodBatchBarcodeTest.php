<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\BloodBatch;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Inventory\Domain\UnitNumberFormat;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class BloodBatchBarcodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
    }

    /** Nama role sengaja literal: ModInventory tidak boleh bergantung ke DomIdentity. */
    private function staffOf(Facility $facility): User
    {
        $staff = User::factory()->create(['facility_id' => $facility->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facility->id));
        $staff->assignRole('hospital_staff');
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $staff;
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
    }

    public function test_a_registered_unit_gets_a_unit_number_and_an_svg_barcode(): void
    {
        $facility = Facility::factory()->create(['code' => 'JKT1']);
        $staff = $this->staffOf($facility);

        $created = $this->postJson('/api/v1/blood-batches', [
            'component' => 'whole_blood',
            'volume_ml' => 450,
            'collected_at' => '2026-09-25T08:00:00+00:00',
            'storage_temperature_c' => 4,
        ], $this->bearer($staff) + ['Idempotency-Key' => (string) Str::uuid()]);

        $created->assertCreated();
        $number = $created->json('data.batch_number');
        $this->assertTrue(UnitNumberFormat::isValid($number), $number);
        $this->assertStringStartsWith('BL-JKT1-260925-', $number);

        $response = $this->get("/api/v1/blood-batches/{$created->json('data.id')}/barcode", $this->bearer($staff));

        $response->assertOk();
        $this->assertStringStartsWith('image/svg+xml', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<svg', (string) $response->getContent());
        $this->assertStringContainsString("<desc>{$number}</desc>", (string) $response->getContent());
    }

    public function test_a_unit_of_another_facility_is_404_not_403(): void
    {
        $staff = $this->staffOf(Facility::factory()->create());
        $other = BloodBatch::factory()->create();

        $this->getJson("/api/v1/blood-batches/{$other->public_id}/barcode", $this->bearer($staff))
            ->assertNotFound();
    }

    public function test_the_barcode_needs_a_token_and_a_public_id(): void
    {
        $staff = $this->staffOf(Facility::factory()->create());
        $own = BloodBatch::factory()->create(['facility_id' => $staff->facility_id]);

        $this->getJson("/api/v1/blood-batches/{$own->public_id}/barcode")->assertUnauthorized();
        // Nomor unit adalah label fisik, bukan identitas rute.
        $this->getJson("/api/v1/blood-batches/{$own->batch_number}/barcode", $this->bearer($staff))->assertNotFound();
        $this->getJson("/api/v1/blood-batches/{$own->id}/barcode", $this->bearer($staff))->assertNotFound();
    }
}
