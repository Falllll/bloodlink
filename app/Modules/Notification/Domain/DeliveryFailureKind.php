<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

enum DeliveryFailureKind: string
{
    /** Layanan luar sedang tidak sehat; percobaan ulang masuk akal. */
    case TRANSIENT = 'transient';

    /** Jawabannya tidak akan berubah; mengulang adalah biaya tanpa peluang. */
    case PERMANENT = 'permanent';
}
