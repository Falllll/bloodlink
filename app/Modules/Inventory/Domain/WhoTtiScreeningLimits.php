<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

final class WhoTtiScreeningLimits
{
    // §6.3: sensitivitas dan spesifisitas assay skrining "preferably" >= 99,5%.
    // Disimpan sebagai master data yang dibaca manusia, BUKAN validasi yang
    // menolak input -- kata "preferably" itu kata WHO sendiri.
    public const float PREFERRED_MIN_SENSITIVITY_PERCENT = 99.5;

    public const float PREFERRED_MIN_SPECIFICITY_PERCENT = 99.5;
}
