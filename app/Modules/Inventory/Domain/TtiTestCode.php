<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * Infeksi menular lewat transfusi (IMLTD/TTI). Empat wajib universal (§6.1)
 * dan tiga menurut pola penyakit regional (§6.2). Mana yang wajib bukan
 * urusan enum ini -- itu master data tti_test_types.
 */
enum TtiTestCode: string
{
    case HIV = 'hiv_1_2';
    case HEPATITIS_B = 'hbsag';
    case HEPATITIS_C = 'hcv';
    case SYPHILIS = 'syphilis';
    case MALARIA = 'malaria';
    case CHAGAS = 'chagas';
    case HTLV = 'htlv';
}
