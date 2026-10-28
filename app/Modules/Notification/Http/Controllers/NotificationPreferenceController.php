<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Modules\Notification\Application\UpdateNotificationPreference;
use App\Modules\Notification\Domain\NotificationCategory;
use App\Modules\Notification\Http\Requests\UpdateNotificationPreferenceRequest;
use App\Shared\Http\ApiResponse;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Preferensi notifikasi milik pengguna yang sedang masuk (kartu 488). Tanpa
 * facility.context: donor tidak punya fasilitas. Hanya kategori yang boleh ditolak
 * yang tampil; kategori wajib tidak pernah muncul sebagai pilihan.
 */
final class NotificationPreferenceController
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->present($this->user($request)));
    }

    public function update(UpdateNotificationPreferenceRequest $request, UpdateNotificationPreference $update): JsonResponse
    {
        $user = $this->user($request);

        $update->handle($user, $request->category(), $request->enabled());

        return ApiResponse::success($this->present($user));
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        $optedOutAt = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->whereNotNull('opted_out_at')
            ->get()
            ->mapWithKeys(fn (NotificationPreference $p): array => [$p->category->value => $p->opted_out_at]);

        $preferences = [];
        foreach (NotificationCategory::optional() as $category) {
            $since = $optedOutAt->get($category->value);
            $preferences[] = [
                'category' => $category->value,
                'enabled' => $since === null,
                'opted_out_at' => $since?->format(DateTimeInterface::ATOM),
            ];
        }

        return [
            // Akibat menolak panggilan darurat dinyatakan terpisah, di tingkat atas --
            // bukan disimpulkan klien dari satu toggle di antara yang lain.
            'callable_for_emergencies' => ! $optedOutAt->has(NotificationCategory::EMERGENCY_CALL->value),
            'preferences' => $preferences,
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
