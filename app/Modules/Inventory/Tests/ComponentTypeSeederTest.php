<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\ComponentStorageProfile;
use App\Models\ComponentType;
use App\Modules\Inventory\Domain\BloodComponent;
use App\Modules\Inventory\Domain\ShelfLifeUnit;
use Database\Seeders\ComponentTypeSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ComponentTypeSeederTest extends TestCase
{
    use RefreshDatabase;

    private function type(BloodComponent $component): ComponentType
    {
        return ComponentType::query()
            ->where('jurisdiction', 'WHO')
            ->where('code', $component->value)
            ->firstOrFail();
    }

    public function test_running_twice_keeps_five_types_and_seven_profiles(): void
    {
        $this->seed(ComponentTypeSeeder::class);
        $this->seed(ComponentTypeSeeder::class);

        $this->assertSame(5, ComponentType::query()->count());
        $this->assertSame(7, ComponentStorageProfile::query()->count());
    }

    public function test_every_component_has_exactly_one_default_profile(): void
    {
        $this->seed(ComponentTypeSeeder::class);

        foreach (BloodComponent::cases() as $component) {
            $this->assertSame(
                1,
                $this->type($component)->storageProfiles()->where('is_default', true)->count(),
                $component->value
            );
        }
    }

    public function test_frozen_components_have_two_profiles_defaulting_to_the_warmer_three_month_band(): void
    {
        $this->seed(ComponentTypeSeeder::class);

        foreach ([BloodComponent::FRESH_FROZEN_PLASMA, BloodComponent::CRYOPRECIPITATE] as $component) {
            $type = $this->type($component);

            $this->assertSame(2, $type->storageProfiles()->count(), $component->value);
            $this->assertSame(
                [3, 12],
                $type->storageProfiles()->orderBy('shelf_life_value')->pluck('shelf_life_value')->all(),
                $component->value
            );

            $default = $type->defaultStorageProfile();

            $this->assertNotNull($default);
            $this->assertSame(3, $default->shelf_life_value);
            $this->assertSame(ShelfLifeUnit::MONTHS, $default->shelf_life_unit);
            $this->assertSame('-24.0', $default->storage_temp_min_celsius);
            $this->assertSame('-20.0', $default->storage_temp_max_celsius);
        }
    }

    public function test_only_platelets_require_agitation(): void
    {
        $this->seed(ComponentTypeSeeder::class);

        $this->assertSame(
            [BloodComponent::PLATELET_CONCENTRATE->value],
            ComponentType::query()->where('requires_agitation', true)->pluck('code')->map->value->all()
        );
    }

    public function test_is_frozen_matches_the_enum(): void
    {
        $this->seed(ComponentTypeSeeder::class);

        foreach (BloodComponent::cases() as $component) {
            $this->assertSame($component->isFrozen(), $this->type($component)->is_frozen, $component->value);
        }
    }

    /** §10.4: 21/42 hari (per antikoagulan) dan trombosit 7 hari bukan angka WHO. */
    public function test_no_non_who_shelf_life_is_seeded(): void
    {
        $this->seed(ComponentTypeSeeder::class);

        $this->assertSame(
            0,
            ComponentStorageProfile::query()
                ->where('shelf_life_unit', 'days')
                ->whereIn('shelf_life_value', [7, 21, 42])
                ->count()
        );
    }

    public function test_every_row_cites_a_mirrored_source(): void
    {
        $this->seed(ComponentTypeSeeder::class);

        $references = ComponentType::query()->pluck('source_reference')
            ->merge(ComponentStorageProfile::query()->pluck('source_reference'));

        $this->assertCount(12, $references);

        foreach ($references as $reference) {
            $this->assertStringContainsString('PDF cermin', $reference);
            $this->assertStringContainsString('§10.2', $reference);
        }
    }

    public function test_the_platelet_source_names_the_conflict_and_the_choice(): void
    {
        $this->seed(ComponentTypeSeeder::class);

        $reference = $this->type(BloodComponent::PLATELET_CONCENTRATE)->source_reference;

        $this->assertStringContainsString('72 jam', $reference);
        $this->assertStringContainsString('5 hari', $reference);
        $this->assertStringContainsString('ikut dokumen B', $reference);
    }

    public function test_three_months_is_calendar_months_not_ninety_days(): void
    {
        $this->seed(ComponentTypeSeeder::class);

        $profile = $this->type(BloodComponent::FRESH_FROZEN_PLASMA)->defaultStorageProfile();
        $this->assertNotNull($profile);

        $this->assertEquals(new DateTimeImmutable('2026-04-15 10:00:00'), $profile->expiryFrom(new DateTimeImmutable('2026-01-15 10:00:00')));
        $this->assertEquals(new DateTimeImmutable('2026-04-30 10:00:00'), $profile->expiryFrom(new DateTimeImmutable('2026-01-31 10:00:00')));
    }

    public function test_a_day_based_shelf_life_adds_days(): void
    {
        $this->seed(ComponentTypeSeeder::class);

        $profile = $this->type(BloodComponent::PACKED_RED_CELLS)->defaultStorageProfile();
        $this->assertNotNull($profile);

        $this->assertEquals(new DateTimeImmutable('2026-03-07 08:00:00'), $profile->expiryFrom(new DateTimeImmutable('2026-01-31 08:00:00')));
    }

    public function test_a_non_positive_shelf_life_value_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ShelfLifeUnit::DAYS->toDateIntervalSpec(0);
    }

    public function test_the_database_rejects_a_band_with_min_above_max(): void
    {
        $this->seed(ComponentTypeSeeder::class);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('component_storage_profiles_band_shape');

        DB::table('component_storage_profiles')->insert([
            'component_type_id' => $this->type(BloodComponent::WHOLE_BLOOD)->id,
            'storage_temp_min_celsius' => '10.0',
            'storage_temp_max_celsius' => '8.0',
            'shelf_life_value' => 1,
            'shelf_life_unit' => 'days',
            'is_default' => false,
            'source_reference' => 'Test fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_rejects_an_overlapping_band_for_the_same_component(): void
    {
        $this->seed(ComponentTypeSeeder::class);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('component_storage_profiles_no_band_overlap');

        // Whole blood sudah punya band [2,6]; [3,7] tidak identik, jadi lolos
        // band_unique -- yang harus menolaknya adalah penjaga tumpang-tindih.
        DB::table('component_storage_profiles')->insert([
            'component_type_id' => $this->type(BloodComponent::WHOLE_BLOOD)->id,
            'storage_temp_min_celsius' => '3.0',
            'storage_temp_max_celsius' => '7.0',
            'shelf_life_value' => 1,
            'shelf_life_unit' => 'days',
            'is_default' => false,
            'source_reference' => 'Test fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_rejects_a_second_default_profile(): void
    {
        $this->seed(ComponentTypeSeeder::class);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('component_storage_profiles_one_default');

        DB::table('component_storage_profiles')->insert([
            'component_type_id' => $this->type(BloodComponent::FRESH_FROZEN_PLASMA)->id,
            'storage_temp_min_celsius' => '-80.0',
            'storage_temp_max_celsius' => '-60.0',
            'shelf_life_value' => 24,
            'shelf_life_unit' => 'months',
            'is_default' => true,
            'source_reference' => 'Test fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_rejects_a_frozen_type_without_a_thaw_window(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('component_types_thaw_shape');

        DB::table('component_types')->insert([
            'jurisdiction' => 'TEST',
            'code' => 'fresh_frozen_plasma',
            'label' => 'FFP tanpa jendela thaw (uji)',
            'is_frozen' => true,
            'post_thaw_window_hours' => null,
            'source_reference' => 'Test fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_rejects_thaw_storage_on_a_non_frozen_type(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('component_types_thaw_shape');

        DB::table('component_types')->insert([
            'jurisdiction' => 'TEST',
            'code' => 'whole_blood',
            'label' => 'Darah lengkap dengan suhu thaw (uji)',
            'is_frozen' => false,
            'post_thaw_storage_min_celsius' => '2.0',
            'post_thaw_storage_max_celsius' => '6.0',
            'source_reference' => 'Test fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_rejects_an_inverted_thaw_band(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('component_types_thaw_shape');

        DB::table('component_types')->insert([
            'jurisdiction' => 'TEST',
            'code' => 'fresh_frozen_plasma',
            'label' => 'FFP dengan band thaw terbalik (uji)',
            'is_frozen' => true,
            'post_thaw_window_hours' => 6,
            'post_thaw_storage_min_celsius' => '6.0',
            'post_thaw_storage_max_celsius' => '2.0',
            'source_reference' => 'Test fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
