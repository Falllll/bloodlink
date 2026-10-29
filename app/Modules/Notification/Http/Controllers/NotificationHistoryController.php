<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Models\NotificationDispatch;
use App\Models\User;
use App\Modules\Notification\Http\Requests\NotificationHistoryRequest;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppliesListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\CursorPaginator;

/**
 * Riwayat notifikasi pengguna yang sedang masuk (kartu 489): "aku tidak pernah dapat"
 * dan "aku melewatkannya" harus bisa dibedakan.
 *
 * Scope /me tanpa id di URL: tidak ada parameter yang bisa diganti untuk membaca
 * riwayat orang lain, jadi tidak ada policy yang harus diingat. Satu item = satu
 * KEJADIAN (notification_dispatches), bukan satu baris per kanal -- email dan push
 * dari kabar yang sama tidak tampil sebagai duplikat. Tanpa isi pesan: klien merender
 * teks dari katalog lokal menurut template_code.
 */
final class NotificationHistoryController
{
    use AppliesListQuery;

    public function index(NotificationHistoryRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = NotificationDispatch::query()->where('user_id', $user->id)->with('deliveries');

        /** @var CursorPaginator<int, NotificationDispatch> $page */
        $page = $this->listing($query, $request);

        return ApiResponse::paginated($page, transform: fn (NotificationDispatch $d): array => $d->toRecipientArray());
    }
}
