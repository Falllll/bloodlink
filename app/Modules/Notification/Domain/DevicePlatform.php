<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

enum DevicePlatform: string
{
    case ANDROID = 'android';
    case IOS = 'ios';
    case WEB = 'web';
}
