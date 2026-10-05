<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ComponentStorageProfile;
use App\Models\ComponentType;
use App\Modules\Inventory\Domain\BloodComponent;
use App\Modules\Inventory\Domain\ShelfLifeUnit;
use Illuminate\Database\Seeder;

/**
 * Semua angka dikutip dari Rujukan Pedoman Medis §10.2. Dokumen WHO dibaca lewat
 * PDF cermin (iris.who.int membalas 403) — cocokkan ulang sebelum rilis publik.
 */
final class ComponentTypeSeeder extends Seeder
{
    private const string JURISDICTION = 'WHO';

    private const string SOURCE_B = 'WHO Cold Chain Manual 2005 Part 2 (dokumen B, PDF cermin — bukan file dari IRIS; '
        .'cocokkan ulang sebelum rilis publik); Rujukan Pedoman Medis §10.2';

    private const string SOURCE_PLATELET = 'WHO Cold Chain Manual 2005 Part 2 (dokumen B, PDF cermin — bukan file dari IRIS; '
        .'cocokkan ulang sebelum rilis publik). Konflik: dokumen A (2001) menulis 72 jam, dokumen B (2005) menulis 5 hari; '
        .'kita ikut dokumen B; Rujukan Pedoman Medis §10.2';

    public function run(): void
    {
        $types = $this->types();

        ComponentType::query()->upsert(
            $types,
            ['jurisdiction', 'code'],
            [
                'label', 'is_frozen', 'requires_agitation', 'post_issue_window_minutes',
                'post_thaw_window_hours', 'post_thaw_storage_min_celsius', 'post_thaw_storage_max_celsius',
                'source_reference', 'is_active',
            ]
        );

        $codes = array_column($types, 'code');

        ComponentType::query()
            ->where('jurisdiction', self::JURISDICTION)
            ->whereNotIn('code', $codes)
            ->update(['is_active' => false]);

        /** @var array<string, int> $ids */
        $ids = ComponentType::query()
            ->where('jurisdiction', self::JURISDICTION)
            ->pluck('id', 'code')
            ->all();

        $profiles = array_map(
            function (array $profile) use ($ids): array {
                $code = $profile['code'];
                unset($profile['code']);

                return ['component_type_id' => $ids[$code]] + $profile;
            },
            $this->profiles()
        );

        ComponentStorageProfile::query()->upsert(
            $profiles,
            ['component_type_id', 'storage_temp_min_celsius', 'storage_temp_max_celsius'],
            ['shelf_life_value', 'shelf_life_unit', 'is_default', 'source_reference']
        );
    }

    /**
     * @return list<array<string, string|int|bool|null>>
     */
    private function types(): array
    {
        return [
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => BloodComponent::WHOLE_BLOOD->value,
                'label' => 'Darah lengkap (whole blood)',
                'is_frozen' => false,
                'requires_agitation' => false,
                'post_issue_window_minutes' => 30,
                'post_thaw_window_hours' => null,
                'post_thaw_storage_min_celsius' => null,
                'post_thaw_storage_max_celsius' => null,
                'source_reference' => self::SOURCE_B,
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => BloodComponent::PACKED_RED_CELLS->value,
                'label' => 'Sel darah merah pekat (packed red cells)',
                'is_frozen' => false,
                'requires_agitation' => false,
                'post_issue_window_minutes' => 30,
                'post_thaw_window_hours' => null,
                'post_thaw_storage_min_celsius' => null,
                'post_thaw_storage_max_celsius' => null,
                'source_reference' => self::SOURCE_B,
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => BloodComponent::PLATELET_CONCENTRATE->value,
                'label' => 'Trombosit pekat (platelet concentrate)',
                'is_frozen' => false,
                'requires_agitation' => true,
                'post_issue_window_minutes' => 30,
                'post_thaw_window_hours' => null,
                'post_thaw_storage_min_celsius' => null,
                'post_thaw_storage_max_celsius' => null,
                'source_reference' => self::SOURCE_PLATELET,
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => BloodComponent::FRESH_FROZEN_PLASMA->value,
                'label' => 'Plasma segar beku (fresh frozen plasma)',
                'is_frozen' => true,
                'requires_agitation' => false,
                'post_issue_window_minutes' => null,
                'post_thaw_window_hours' => 6,
                'post_thaw_storage_min_celsius' => '2.0',
                'post_thaw_storage_max_celsius' => '6.0',
                'source_reference' => self::SOURCE_B,
                'is_active' => true,
            ],
            [
                'jurisdiction' => self::JURISDICTION,
                'code' => BloodComponent::CRYOPRECIPITATE->value,
                'label' => 'Kriopresipitat (cryoprecipitate)',
                'is_frozen' => true,
                'requires_agitation' => false,
                'post_issue_window_minutes' => null,
                'post_thaw_window_hours' => 6,
                'post_thaw_storage_min_celsius' => null,
                'post_thaw_storage_max_celsius' => null,
                'source_reference' => self::SOURCE_B,
                'is_active' => true,
            ],
        ];
    }

    /**
     * Band −20…−24 °C sengaja jadi default untuk FFP & cryo: lebih konservatif,
     * jadi fasilitas berfreezer hangat tidak pernah melebih-lebihkan masa simpan.
     *
     * @return list<array<string, string|int|bool>>
     */
    private function profiles(): array
    {
        return [
            $this->profile(BloodComponent::WHOLE_BLOOD, '2.0', '6.0', 35, ShelfLifeUnit::DAYS, true, self::SOURCE_B),
            $this->profile(BloodComponent::PACKED_RED_CELLS, '2.0', '6.0', 35, ShelfLifeUnit::DAYS, true, self::SOURCE_B),
            $this->profile(BloodComponent::PLATELET_CONCENTRATE, '20.0', '24.0', 5, ShelfLifeUnit::DAYS, true, self::SOURCE_PLATELET),
            $this->profile(BloodComponent::FRESH_FROZEN_PLASMA, '-24.0', '-20.0', 3, ShelfLifeUnit::MONTHS, true, self::SOURCE_B),
            $this->profile(BloodComponent::FRESH_FROZEN_PLASMA, '-39.0', '-30.0', 12, ShelfLifeUnit::MONTHS, false, self::SOURCE_B),
            $this->profile(BloodComponent::CRYOPRECIPITATE, '-24.0', '-20.0', 3, ShelfLifeUnit::MONTHS, true, self::SOURCE_B),
            $this->profile(BloodComponent::CRYOPRECIPITATE, '-39.0', '-30.0', 12, ShelfLifeUnit::MONTHS, false, self::SOURCE_B),
        ];
    }

    /** @return array<string, string|int|bool> */
    private function profile(
        BloodComponent $component,
        string $minCelsius,
        string $maxCelsius,
        int $shelfLifeValue,
        ShelfLifeUnit $shelfLifeUnit,
        bool $isDefault,
        string $sourceReference,
    ): array {
        return [
            'code' => $component->value,
            'storage_temp_min_celsius' => $minCelsius,
            'storage_temp_max_celsius' => $maxCelsius,
            'shelf_life_value' => $shelfLifeValue,
            'shelf_life_unit' => $shelfLifeUnit->value,
            'is_default' => $isDefault,
            'source_reference' => $sourceReference,
        ];
    }
}
