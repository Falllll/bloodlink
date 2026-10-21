<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Mail;

use Illuminate\Mail\Mailable;

/**
 * Email dari satu RenderedNotification, tanpa view (repo ini headless: nol Blade).
 * Body di-escape di SINI: RenderNotification menyisipkan nilai apa adanya, dan
 * escape HTML adalah urusan pengirim.
 *
 * Tidak ShouldQueue: ia dikirim sinkron di dalam job SendNotification.
 */
final class RenderedNotificationMail extends Mailable
{
    public function __construct(string $subjectLine, string $body)
    {
        $this->subject($subjectLine);
        $this->html(nl2br(e($body), false));
    }
}
