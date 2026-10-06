<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\BloodBatch;
use App\Models\Facility;
use App\Modules\Inventory\Application\Exceptions\UnitNumberAllocationFailed;
use App\Modules\Inventory\Application\GenerateUnitNumber;
use App\Modules\Inventory\Domain\UnitNumberFormat;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UnitNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_generated_number_matches_the_format_and_is_22_characters(): void
    {
        $number = (new GenerateUnitNumber)->next('JKT1', new DateTimeImmutable('2026-09-25 10:00:00'));

        $this->assertMatchesRegularExpression(UnitNumberFormat::PATTERN, $number);
        $this->assertSame(UnitNumberFormat::LENGTH, strlen($number));
        $this->assertStringStartsWith('BL-JKT1-260925-0001', $number);
        $this->assertTrue(UnitNumberFormat::isValid($number));
    }

    public function test_the_sequence_counts_per_facility_prefix_and_day(): void
    {
        $generator = new GenerateUnitNumber;
        $day = new DateTimeImmutable('2026-09-25 10:00:00');

        $this->assertStringStartsWith('BL-JKT1-260925-0001', $generator->next('JKT1', $day));
        $this->assertStringStartsWith('BL-JKT1-260925-0002', $generator->next('JKT1', $day));
        $this->assertStringStartsWith('BL-BKS1-260925-0001', $generator->next('BKS1', $day));
        $this->assertStringStartsWith('BL-JKT1-260926-0001', $generator->next('JKT1', $day->modify('+1 day')));
    }

    public function test_the_facility_prefix_uses_the_crockford_alphabet(): void
    {
        $this->assertSame('FAC8', UnitNumberFormat::facilityPrefix('FAC-8511'));
        // I/L -> 1, O -> 0, U -> V; dilengkapi '0' sampai 4 karakter.
        $this->assertSame('110V', UnitNumberFormat::facilityPrefix('ilou'));
        $this->assertSame('RS00', UnitNumberFormat::facilityPrefix('rs'));
    }

    public function test_the_check_character_follows_iso_7064_mod_37_36(): void
    {
        // Contoh dari standar ISO 7064 untuk sistem MOD 37,36.
        $this->assertSame('M', UnitNumberFormat::checkCharacter('A12425GABC1234002'));
    }

    public function test_a_single_mistyped_character_fails_the_check(): void
    {
        $number = UnitNumberFormat::compose('JKT1', '260925', 7, 'A9');
        $mistyped = substr_replace($number, '8', 15, 1); // SEQ 0007 -> 8007

        $this->assertTrue(UnitNumberFormat::isValid($number));
        $this->assertFalse(UnitNumberFormat::isValid($mistyped));
    }

    public function test_the_database_rejects_a_duplicate_batch_number(): void
    {
        $row = fn (): array => [
            'public_id' => (string) Str::uuid(),
            'batch_number' => 'BL-JKT1-260925-0007A9L',
            'facility_id' => Facility::factory()->create()->id,
            'component' => 'whole_blood',
            'volume_ml' => 450,
            'status' => 'quarantined',
            'collected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('blood_batches')->insert($row());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('blood_batches_batch_number_unique');

        DB::table('blood_batches')->insert($row());
    }

    public function test_a_forced_collision_is_retried_with_a_different_number(): void
    {
        $facility = Facility::factory()->create(['code' => 'JKT1']);
        $squatter = BloodBatch::factory()->create(['facility_id' => $facility->id]);
        $batch = BloodBatch::factory()->make(['facility_id' => $facility->id]);
        $attempts = [];

        $number = (new GenerateUnitNumber)->retrying('JKT1', new DateTimeImmutable('2026-09-25'), function (string $candidate) use ($batch, $squatter, &$attempts): bool {
            $attempts[] = $candidate;

            // Percobaan pertama: nomor itu "sudah dipakai" unit lain sesaat sebelumnya.
            if (count($attempts) === 1) {
                $squatter->forceFill(['batch_number' => $candidate])->save();
            }

            return $batch->forceFill(['batch_number' => $candidate])->save();
        });

        $this->assertCount(2, $attempts);
        $this->assertNotSame($attempts[0], $number);
        $this->assertSame($number, $batch->fresh()?->batch_number);
        $this->assertSame([], DB::select('SELECT batch_number FROM blood_batches GROUP BY batch_number HAVING COUNT(*) > 1'));
    }

    public function test_an_exhausted_daily_sequence_is_refused_not_wrapped(): void
    {
        DB::table('blood_unit_number_sequences')->insert([
            'prefix' => 'JKT1', 'period' => '260925', 'last_value' => UnitNumberFormat::MAX_SEQUENCE,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(UnitNumberAllocationFailed::class);

        (new GenerateUnitNumber)->next('JKT1', new DateTimeImmutable('2026-09-25'));
    }

    public function test_the_backfill_renumbers_stale_rows_once(): void
    {
        BloodBatch::factory()->count(3)->create(); // factory: 'BB-########', bukan nomor unit

        $this->artisan('inventory:backfill-batch-numbers')
            ->expectsOutputToContain('Dry run: 3')
            ->assertSuccessful();
        $this->assertSame(0, BloodBatch::query()->get()->filter(fn (BloodBatch $b) => UnitNumberFormat::isValid($b->batch_number))->count());

        $this->artisan('inventory:backfill-batch-numbers', ['--force' => true])
            ->expectsOutputToContain('Menomori ulang 3 unit.')
            ->assertSuccessful();
        $this->artisan('inventory:backfill-batch-numbers', ['--force' => true])
            ->expectsOutputToContain('Menomori ulang 0 unit.')
            ->assertSuccessful();

        foreach (BloodBatch::query()->get() as $batch) {
            $this->assertTrue(UnitNumberFormat::isValid($batch->batch_number), $batch->batch_number);
        }
    }
}
