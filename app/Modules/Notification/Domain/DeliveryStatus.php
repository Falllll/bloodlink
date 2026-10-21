<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

/** Nilai harus sama dengan enum kolom notification_deliveries.status. */
enum DeliveryStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case FAILED = 'failed';
}
