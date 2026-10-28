<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Notification\Domain\NotificationCategory;
use App\Modules\Notification\Domain\NotificationChannel;
use App\Shared\Database\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu template pesan per (code, channel, locale). Dirender HANYA lewat
 * RenderNotification, yang menolak variabel kurang maupun berlebih.
 *
 * @property int $id
 * @property string $code
 * @property NotificationChannel $channel
 * @property NotificationCategory $category
 * @property string $locale
 * @property string|null $subject
 * @property string $body
 * @property list<string> $variables
 * @property bool $is_active
 */
final class NotificationTemplate extends Model
{
    use Auditable;

    protected $fillable = ['code', 'channel', 'category', 'locale', 'subject', 'body', 'variables', 'is_active'];

    protected $hidden = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'category' => NotificationCategory::class,
            'variables' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
