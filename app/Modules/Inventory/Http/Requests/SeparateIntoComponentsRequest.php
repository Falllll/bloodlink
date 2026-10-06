<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Domain\BloodComponent;
use App\Modules\Inventory\Domain\ComponentSeparationPlan;
use App\Modules\Inventory\Domain\DerivedComponentSpec;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

final class SeparateIntoComponentsRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $derivable = array_values(array_filter(
            BloodComponent::cases(),
            fn (BloodComponent $component): bool => $component !== BloodComponent::WHOLE_BLOOD,
        ));

        return [
            'components' => ['required', 'array', 'min:1', 'max:10'],
            'components.*.component' => ['required', Rule::enum(BloodComponent::class)->only($derivable)],
            'components.*.volume_ml' => ['required', 'integer', 'min:1'],
            // Suhu simpan tiap turunan: masa simpannya diturunkan dari profil suhu ini.
            'components.*.storage_temperature_c' => ['required', 'numeric'],
        ];
    }

    public function plan(): ComponentSeparationPlan
    {
        /** @var list<array{component: string, volume_ml: int|string, storage_temperature_c: int|float|string}> $components */
        $components = $this->validated('components');

        return new ComponentSeparationPlan(array_map(
            fn (array $row): DerivedComponentSpec => new DerivedComponentSpec(
                BloodComponent::from($row['component']),
                (int) $row['volume_ml'],
                (float) $row['storage_temperature_c'],
            ),
            $components,
        ));
    }
}
