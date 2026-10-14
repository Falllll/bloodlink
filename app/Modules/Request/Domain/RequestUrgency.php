<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain;

/**
 * Tingkat kedaruratan, bukan angka prioritas: angka mengundang aritmetika
 * ("dua kali lebih penting") yang tidak punya arti medis.
 */
enum RequestUrgency: string
{
    case ROUTINE = 'routine';
    case URGENT = 'urgent';
    case EMERGENCY = 'emergency';
}
