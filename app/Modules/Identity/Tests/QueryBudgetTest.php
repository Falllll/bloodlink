<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests;

use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Identity\Domain\Role;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InspectsQueries;
use Tests\TestCase;

/**
 * Anggaran query modul Identity (kartu 445). Satu user terautentikasi per test: guard
 * Sanctum memegang user dari request pertama.
 */
final class QueryBudgetTest extends TestCase
{
    use InspectsQueries;
    use RefreshDatabase;

    /**
     * Ketiadaan with() di AuditLogController::index() DISENGAJA: serializer-nya skalar.
     * actor_id terisi supaya relasi yang kelak dibaca benar-benar memicu satu query per baris.
     */
    public function test_listing_audit_logs_costs_the_same_for_two_or_eight_rows(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['facility_id' => null]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of(null));
        $admin->assignRole(Role::ADMIN->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        $headers = ['Authorization' => 'Bearer '.$admin->createToken('api')->plainTextToken];
        $facility = Facility::factory()->create();
        $seed = fn () => AuditLog::create([
            'auditable_type' => Facility::class,
            'auditable_id' => $facility->id,
            'action' => 'updated',
            'actor_id' => $admin->id,
            'actor_facility_id' => $facility->id,
            'changes' => ['before' => [], 'after' => []],
            'trace_id' => null,
            'ip' => null,
            'occurred_at' => now(),
        ]);

        foreach (range(1, 2) as $_) {
            $seed();
        }
        $few = $this->getCost('/api/v1/audit-logs', $headers);

        foreach (range(1, 6) as $_) {
            $seed();
        }
        $many = $this->getCost('/api/v1/audit-logs', $headers);

        $this->assertSame($few, $many);
        $this->assertGreaterThanOrEqual(8, count($this->getJson('/api/v1/audit-logs', $headers)->json('data')));
    }
}
