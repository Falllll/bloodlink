<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Models\Donor;
use App\Modules\Donor\Domain\Events\DonorLocationChanged;
use App\Modules\Donor\Infrastructure\DonorGeoIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Indeks GEO Redis (kartu 390) terhadap Redis sungguhan, dengan key unik per test
 * supaya tidak pernah menyentuh key dev. Titik asal Monas, sama dengan fixture
 * UrgentRequestTest: 1 derajat lintang ~ 111 km, jadi +0.027 ~ 3 km ke utara.
 */
final class DonorGeoIndexTest extends TestCase
{
    use RefreshDatabase;

    private const float LAT = -6.1754;

    private const float LNG = 106.8272;

    private mixed $redisPort;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redisPort = config('database.redis.default.port');

        config([
            'matching.redis_geo_enabled' => true,
            'matching.redis_geo_key' => 'geo:donors:test:'.Str::uuid(),
        ]);
    }

    protected function tearDown(): void
    {
        // Test kegagalan Redis sengaja merusak port; pulihkan sebelum membersihkan key.
        // RedisManager menyimpan config saat pertama dibangun: buang instansnya.
        config(['database.redis.default.port' => $this->redisPort]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
        Redis::del((string) config('matching.redis_geo_key'));

        parent::tearDown();
    }

    private function index(): DonorGeoIndex
    {
        return app(DonorGeoIndex::class);
    }

    private function donorAt(?float $northDegrees): Donor
    {
        $donor = Donor::factory()->create();

        if ($northDegrees !== null) {
            DB::table('donors')->where('id', $donor->id)->update([
                'location' => DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', self::LNG, self::LAT + $northDegrees)),
            ]);
        }

        return $donor;
    }

    public function test_a_donor_three_km_away_is_inside_five_km_and_outside_two_km(): void
    {
        $this->index()->put(42, self::LAT + 0.027, self::LNG);

        $this->assertSame([42], $this->index()->within(self::LAT, self::LNG, 5000));
        $this->assertSame([], $this->index()->within(self::LAT, self::LNG, 2000));
    }

    public function test_results_come_nearest_first(): void
    {
        $this->index()->put(3, self::LAT + 0.040, self::LNG);
        $this->index()->put(1, self::LAT + 0.010, self::LNG);
        $this->index()->put(2, self::LAT + 0.020, self::LNG);

        $this->assertSame([1, 2, 3], $this->index()->within(self::LAT, self::LNG, 10000));
    }

    /** Selisih Redis (bola Haversine) vs PostGIS (sferoid) harus di bawah 1%. */
    public function test_the_distance_matches_postgis_within_one_percent(): void
    {
        $donor = $this->donorAt(0.09);
        $this->index()->put($donor->id, self::LAT + 0.09, self::LNG);

        // phpredis membalas WITHDIST sebagai [member => [jarak]].
        /** @var array<int|string, array{0: float}> $hits */
        $hits = Redis::connection()->command('geosearch', [(string) config('matching.redis_geo_key'), [self::LNG, self::LAT], 20000, 'm', ['WITHDIST']]);
        $redis = (float) $hits[$donor->id][0];
        $postgis = (float) DB::table('donors')->where('id', $donor->id)
            ->selectRaw('ST_Distance(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS d', [self::LNG, self::LAT])
            ->value('d');

        $this->assertLessThan(0.01, abs($redis - $postgis) / $postgis, "redis {$redis} m vs postgis {$postgis} m");
    }

    public function test_forget_removes_the_donor(): void
    {
        $this->index()->put(7, self::LAT, self::LNG);
        $this->index()->forget(7);

        $this->assertSame(0, $this->index()->count());
    }

    public function test_the_disabled_flag_writes_nothing(): void
    {
        config(['matching.redis_geo_enabled' => false]);

        $this->index()->put(7, self::LAT, self::LNG);
        event(new DonorLocationChanged(8, self::LAT, self::LNG));

        $this->assertSame(0, $this->index()->count());
    }

    public function test_the_listener_writes_through_on_a_location_change(): void
    {
        event(new DonorLocationChanged(9, self::LAT + 0.01, self::LNG));

        $this->assertSame([9], $this->index()->within(self::LAT, self::LNG, 5000));
    }

    /** Null berarti lokasi dihapus -- bukan titik (0,0) di Teluk Guinea. */
    public function test_null_coordinates_forget_instead_of_landing_at_zero_zero(): void
    {
        $this->index()->put(9, self::LAT, self::LNG);

        event(new DonorLocationChanged(9, null, null));

        $this->assertSame(0, $this->index()->count());
        $this->assertSame([], $this->index()->within(0.0, 0.0, 1000));
    }

    public function test_a_redis_failure_is_logged_and_never_thrown(): void
    {
        $log = Log::spy();
        config(['database.redis.default.port' => 1, 'database.redis.default.max_retries' => 0]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');

        event(new DonorLocationChanged(9, self::LAT, self::LNG));

        $log->shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Donor geo index sync failed') && $context['donor_id'] === 9);
    }

    public function test_reconcile_without_force_changes_nothing(): void
    {
        $this->donorAt(0.01);
        $this->donorAt(0.02);

        $this->artisan('donor:reconcile-geo')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame(0, $this->index()->count());
    }

    public function test_reconcile_with_force_mirrors_postgis_and_drops_stale_members(): void
    {
        $a = $this->donorAt(0.01);
        $b = $this->donorAt(0.02);
        $this->donorAt(null);
        $gone = $this->donorAt(0.03);
        $gone->delete();
        $this->index()->put(999999, self::LAT, self::LNG);   // basi: tidak ada di PostGIS

        $this->artisan('donor:reconcile-geo --force')->assertSuccessful();

        $this->assertSame(2, $this->index()->count());
        $this->assertSame([$a->id, $b->id], $this->index()->within(self::LAT, self::LNG, 10000));
    }

    public function test_reconcile_writes_nothing_while_the_flag_is_off(): void
    {
        $this->donorAt(0.01);
        config(['matching.redis_geo_enabled' => false]);

        $this->artisan('donor:reconcile-geo --force')
            ->expectsOutputToContain('redis_geo_enabled mati')
            ->assertSuccessful();

        $this->assertSame(0, $this->index()->count());
    }
}
