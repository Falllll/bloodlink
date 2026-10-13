<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * Peruntukan lokasi: empat kategori pemisahan fisik unit (Kartu 250) plus
 * GENERAL. Nilai harus sama persis dengan enum kolom storage_locations.purpose.
 */
enum StoragePurpose: string
{
    case UNTESTED = 'untested';
    case REACTIVE = 'reactive';
    case INDETERMINATE = 'indeterminate';
    case RELEASE_READY = 'release_ready';
    case GENERAL = 'general';
}
