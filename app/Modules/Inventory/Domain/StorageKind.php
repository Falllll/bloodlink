<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/** Jenis alat penyimpanan yang nyata. Nilai harus sama persis dengan enum kolom storage_locations.kind. */
enum StorageKind: string
{
    case REFRIGERATOR = 'refrigerator';
    case FREEZER = 'freezer';
    case DEEP_FREEZER = 'deep_freezer';
    case PLATELET_AGITATOR = 'platelet_agitator';
    case AMBIENT_SHELF = 'ambient_shelf';
}
