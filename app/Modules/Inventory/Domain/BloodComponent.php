<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

enum BloodComponent: string
{
    case WHOLE_BLOOD = 'whole_blood';
    case PACKED_RED_CELLS = 'packed_red_cells';
    case FRESH_FROZEN_PLASMA = 'fresh_frozen_plasma';
    case PLATELET_CONCENTRATE = 'platelet_concentrate';
    case CRYOPRECIPITATE = 'cryoprecipitate';

    public function isFrozen(): bool
    {
        return match ($this) {
            self::FRESH_FROZEN_PLASMA, self::CRYOPRECIPITATE => true,
            self::WHOLE_BLOOD, self::PACKED_RED_CELLS, self::PLATELET_CONCENTRATE => false,
        };
    }
}
