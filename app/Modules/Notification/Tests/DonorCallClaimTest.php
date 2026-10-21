<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Models\Donor;
use App\Models\DonorCallClaim;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Notification\Application\ClaimDonorCalls;
use App\Shared\Matching\DonorCallGuard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InspectsQueries;
use Tests\TestCase;

/**
 * Penjaga satu panggilan per donor per permintaan (kartu 480), satu proses.
 * Lomba sungguhan antar-proses ada di DonorCallClaimRaceTest -- test di sini hijau
 * juga untuk penjaga `if` di PHP, jadi BUKAN bukti penjaganya. Penyambungan ke
 * kampanye (kartu 400) diuji di modul Request: DonorCampaignClaimTest.
 */
final class DonorCallClaimTest extends TestCase
{
    use InspectsQueries;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
    }

    private function guard(): DonorCallGuard
    {
        return app(DonorCallGuard::class);
    }

    /**
     * Baris permintaan minimal lewat tabel: modul Notification tidak boleh memanggil
     * service modul Request (deptrac), dan penjaga ini hanya butuh id-nya.
     */
    private function request(string $status = 'open'): int
    {
        $facility = Facility::factory()->create();

        return (int) DB::table('blood_requests')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'facility_id' => $facility->id,
            'patient_reference' => 'RM-'.Str::random(6),
            'component' => 'packed_red_cells',
            'blood_group' => 'O',
            'rh_factor' => 'negative',
            'quantity' => 1,
            'urgency' => 'emergency',
            'status' => $status,
            'needed_by' => now()->addHours(6),
            'requested_by' => User::factory()->create(['facility_id' => $facility->id])->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<int> */
    private function donorIds(int $count): array
    {
        return Donor::factory()->count($count)->create()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function claim(int $requestId, array $ids, int $radius = 5000): array
    {
        $claimed = $this->guard()->claim($requestId, $ids, $radius, now()->toDateTimeImmutable());
        sort($claimed);

        return $claimed;
    }

    public function test_the_port_is_bound_to_the_unique_index_guard(): void
    {
        $this->assertInstanceOf(ClaimDonorCalls::class, $this->guard());
    }

    public function test_a_first_claim_returns_every_donor(): void
    {
        $request = $this->request();
        $ids = $this->donorIds(3);

        $this->assertSame($ids, $this->claim($request, $ids));
        $this->assertSame(3, DonorCallClaim::query()->count());
    }

    public function test_claiming_the_same_donors_again_returns_an_empty_list(): void
    {
        $request = $this->request();
        $ids = $this->donorIds(3);
        $this->claim($request, $ids);

        $this->assertSame([], $this->claim($request, $ids));
        $this->assertSame(3, DonorCallClaim::query()->count());
    }

    public function test_a_mix_of_old_and_new_donors_returns_only_the_new_ones(): void
    {
        $request = $this->request();
        [$a, $b, $c, $d] = $this->donorIds(4);
        $this->claim($request, [$a, $b]);

        $this->assertSame([$c, $d], $this->claim($request, [$a, $c, $b, $d]));
    }

    public function test_duplicate_ids_in_one_batch_claim_once(): void
    {
        $request = $this->request();
        [$a] = $this->donorIds(1);

        $this->assertSame([$a], $this->claim($request, [$a, $a, $a]));
    }

    public function test_an_empty_batch_runs_no_query(): void
    {
        $request = $this->request();

        $this->assertSame(0, $this->countQueries(fn () => $this->claim($request, [])));
    }

    public function test_the_same_donor_can_be_claimed_for_a_different_request(): void
    {
        [$a] = $this->donorIds(1);

        $this->assertSame([$a], $this->claim($this->request(), [$a]));
        $this->assertSame([$a], $this->claim($this->request(), [$a]));
    }

    public function test_the_first_stage_radius_is_kept_and_never_overwritten(): void
    {
        $request = $this->request();
        [$a] = $this->donorIds(1);

        $this->claim($request, [$a], 5000);
        $this->travel(20)->minutes();
        $this->claim($request, [$a], 15000);

        $claim = DonorCallClaim::query()->sole();
        $this->assertSame(5000, $claim->radius_meters);
        $this->assertSame(now()->subMinutes(20)->toAtomString(), $claim->claimed_at->toAtomString());
    }

    public function test_a_radius_beyond_a_smallint_is_stored(): void
    {
        // unsignedSmallInteger di PostgreSQL = smallint bertanda, maksimum 32767.
        $request = $this->request();
        [$a] = $this->donorIds(1);

        $this->claim($request, [$a], 70000);

        $this->assertSame(70000, DonorCallClaim::query()->sole()->radius_meters);
    }

    public function test_already_claimed_lists_the_donors_of_that_request_only(): void
    {
        $request = $this->request();
        [$a, $b, $c] = $this->donorIds(3);
        $this->claim($request, [$a, $b]);
        $this->claim($this->request(), [$c]);

        $already = $this->guard()->alreadyClaimed($request);
        sort($already);
        $this->assertSame([$a, $b], $already);
    }

    public function test_claiming_two_hundred_donors_is_one_query(): void
    {
        $request = $this->request();
        $ids = $this->donorIds(200);

        $queries = $this->countQueries(fn () => $this->claim($request, $ids));

        $this->assertSame(1, $queries);
        $this->assertSame(200, DonorCallClaim::query()->count());
    }

    public function test_an_unknown_donor_fails_on_the_foreign_key(): void
    {
        $request = $this->request();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('donor_call_claims_donor_id_foreign');

        $this->claim($request, [999_999]);
    }

    public function test_no_http_idempotency_key_is_ever_written(): void
    {
        $request = $this->request();
        $this->claim($request, $this->donorIds(5));
        $this->claim($request, $this->donorIds(5));

        $this->assertSame(0, DB::table('idempotency_keys')->count());
    }

    public function test_prune_without_force_deletes_nothing_and_with_force_only_old_terminal_claims(): void
    {
        $done = $this->request('fulfilled');
        $open = $this->request();
        $this->claim($done, $this->donorIds(2));
        $this->claim($open, $this->donorIds(3));

        $this->travel(91)->days();

        $this->artisan('notification:prune-claims')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('2 donor call claim(s) would be deleted.')
            ->assertSuccessful();
        $this->assertSame(5, DonorCallClaim::query()->count());

        $this->artisan('notification:prune-claims', ['--force' => true])
            ->expectsOutputToContain('Deleted 2 donor call claim(s).')
            ->assertSuccessful();

        // Klaim permintaan yang masih terbuka adalah penjaga yang sedang bekerja.
        $this->assertSame(3, DonorCallClaim::query()->where('blood_request_id', $open)->count());
        $this->assertSame(0, DonorCallClaim::query()->where('blood_request_id', $done)->count());
    }

    public function test_prune_keeps_terminal_claims_inside_the_retention_window(): void
    {
        $done = $this->request('cancelled');
        $this->claim($done, $this->donorIds(2));

        $this->travel(89)->days();

        $this->artisan('notification:prune-claims', ['--force' => true])->assertSuccessful();
        $this->assertSame(2, DonorCallClaim::query()->count());
    }
}
