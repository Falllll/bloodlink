<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

/**
 * Kanal pengiriman. Hanya dua, dan sengaja: case enum tanpa implementasi adalah
 * janji yang tidak ditepati compiler. Alasan kebijakannya ditulis kartu 455.
 */
enum NotificationChannel: string
{
    case EMAIL = 'email';
    case PUSH = 'push';
}
