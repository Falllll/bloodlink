<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain;

/** Label yang sama dengan Rujukan Pedoman Medis: "Dikutip" / "Turunan". */
enum RuleProvenance: string
{
    case CITED = 'cited';
    case DERIVED = 'derived';
}
