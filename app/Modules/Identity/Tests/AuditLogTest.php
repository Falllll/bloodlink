<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests;

use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Infrastructure\Auditing\AuditObserver;
use App\Shared\Auth\FacilityScope;
use App\Shared\Logging\SensitiveKeys;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function assignRole(User $user, RoleEnum $role): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($user->facility_id));
        $user->assignRole($role->value);

        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);
    }

    /**
     * @return array<string, string>
     */
    private function bearer(User $user): array
    {
        $token = $user->createToken('api')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function withIdempotencyKey(array $headers): array
    {
        return array_merge($headers, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_creating_a_facility_writes_one_audit_row(): void
    {
        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        $response = $this->postJson('/api/v1/facilities', [
            'code' => 'AUD-01',
            'name' => 'RSUD Audit',
            'type' => 'hospital',
            'address' => 'Jl. Audit No. 1',
            'city' => 'Jakarta',
            'province' => 'DKI Jakarta',
            'phone' => '02112345678',
            'latitude' => -6.2,
            'longitude' => 106.81,
        ], $this->withIdempotencyKey($this->bearer($admin)));

        $response->assertStatus(201);

        $this->assertSame(1, AuditLog::where('auditable_type', Facility::class)->where('action', 'created')->count());

        $log = AuditLog::where('auditable_type', Facility::class)->where('action', 'created')->first();
        $this->assertSame($admin->id, $log->actor_id);
    }

    public function test_updating_records_only_the_changed_columns(): void
    {
        $facility = Facility::factory()->create(['name' => 'Nama Lama', 'city' => 'Jakarta']);

        $facility->update(['name' => 'Nama Baru']);

        $log = AuditLog::where('auditable_type', Facility::class)
            ->where('auditable_id', $facility->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($log);
        $this->assertArrayHasKey('name', $log->changes['after']);
        $this->assertArrayNotHasKey('city', $log->changes['after']);
        $this->assertSame('Nama Lama', $log->changes['before']['name']);
        $this->assertSame('Nama Baru', $log->changes['after']['name']);
    }

    public function test_a_no_op_save_writes_nothing(): void
    {
        $facility = Facility::factory()->create();

        $countBefore = AuditLog::count();

        // save() biasa tanpa atribut dirty tidak pernah memicu event
        // 'updated' sama sekali, jadi tidak membuktikan apa-apa soal
        // observer. Untuk benar-benar menguji "diff kosong setelah
        // disaring tidak ditulis", kotori hanya updated_at (kolom yang
        // dikecualikan auditExcept()) lalu panggil observer langsung.
        $facility->setAttribute('updated_at', $facility->updated_at->addSecond());
        $facility->syncChanges();

        (new AuditObserver)->updated($facility);

        $this->assertSame($countBefore, AuditLog::count());
    }

    public function test_sensitive_values_are_redacted(): void
    {
        // Tidak ada kolom di Facility/Donor/BloodBatch yang secara alami
        // bernama seperti kunci sensitif, jadi observer dipanggil langsung
        // dengan atribut yang disimulasikan berubah untuk membuktikan filter
        // redaksinya, tanpa bergantung pada skema kolom yang kebetulan cocok.
        $facility = Facility::factory()->create();
        $facility->setAttribute('secret', 'super-secret-value');
        $facility->syncChanges();

        (new AuditObserver)->updated($facility);

        $log = AuditLog::where('auditable_type', Facility::class)
            ->where('auditable_id', $facility->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(SensitiveKeys::REDACTED, $log->changes['after']['secret']);
    }

    public function test_the_actor_and_trace_id_are_recorded(): void
    {
        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        $traceId = (string) Str::uuid();

        $response = $this->postJson('/api/v1/facilities', [
            'code' => 'AUD-02',
            'name' => 'RSUD Trace',
            'type' => 'hospital',
            'address' => 'Jl. Trace No. 1',
            'city' => 'Jakarta',
            'province' => 'DKI Jakarta',
            'phone' => '02112345679',
            'latitude' => -6.2,
            'longitude' => 106.81,
        ], array_merge($this->withIdempotencyKey($this->bearer($admin)), ['X-Request-Id' => $traceId]));

        $response->assertStatus(201);
        $this->assertSame($traceId, $response->headers->get('X-Request-Id'));

        $log = AuditLog::where('auditable_type', Facility::class)->where('action', 'created')->first();

        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($traceId, $log->trace_id);
    }

    public function test_an_audit_row_cannot_be_updated(): void
    {
        $facility = Facility::factory()->create();
        $log = AuditLog::where('auditable_type', Facility::class)->where('auditable_id', $facility->id)->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']);
    }

    public function test_an_audit_row_cannot_be_deleted(): void
    {
        $facility = Facility::factory()->create();
        $log = AuditLog::where('auditable_type', Facility::class)->where('auditable_id', $facility->id)->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('audit_logs')->where('id', $log->id)->delete();
    }
}
