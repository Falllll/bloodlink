<?php

declare(strict_types=1);

namespace App\Shared\Blood;

/**
 * Golongan ABO. Nilainya persis enum kolom blood_group di PostgreSQL.
 * Di Shared, bukan di modul: Donor, Inventory, dan Request sama-sama memakainya,
 * dan deptrac hanya mengizinkan lintas modul lewat Domain/Events.
 */
enum BloodGroup: string
{
    case A = 'A';
    case B = 'B';
    case AB = 'AB';
    case O = 'O';
}
