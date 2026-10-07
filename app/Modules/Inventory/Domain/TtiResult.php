<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/** Tiga nilai, bukan boolean: §5 menyimpan unit indeterminate di kategori tersendiri. */
enum TtiResult: string
{
    case NON_REACTIVE = 'non_reactive';
    case REACTIVE = 'reactive';
    case INDETERMINATE = 'indeterminate';
}
