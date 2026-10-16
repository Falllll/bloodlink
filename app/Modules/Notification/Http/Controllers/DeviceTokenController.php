<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Models\DeviceToken;
use App\Models\User;
use App\Modules\Notification\Application\RegisterDeviceToken;
use App\Modules\Notification\Application\RevokeDeviceToken;
use App\Modules\Notification\Http\Requests\RegisterDeviceTokenRequest;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registri token push milik pengguna yang sedang masuk. Tanpa facility.context:
 * donor tidak punya fasilitas (kartu 255).
 */
final class DeviceTokenController
{
    public function store(RegisterDeviceTokenRequest $request, RegisterDeviceToken $registerDeviceToken): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $device = $registerDeviceToken->handle($user, $request->validated('token'), $request->platform());

        return ApiResponse::success($device->toApiArray())->setStatusCode(201);
    }

    public function destroy(Request $request, string $deviceToken, RevokeDeviceToken $revokeDeviceToken): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Kepemilikan diperiksa di query, bukan lewat route model binding: token milik
        // orang lain dibalas 404, sama dengan token yang tidak ada -- tidak ada bocoran
        // bahwa public_id itu sah.
        $device = DeviceToken::query()
            ->where('public_id', $deviceToken)
            ->where('user_id', $user->id)
            ->firstOrFail();

        return ApiResponse::success($revokeDeviceToken->handle($device)->toApiArray());
    }
}
