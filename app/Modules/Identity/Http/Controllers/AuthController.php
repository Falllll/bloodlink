<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Shared\Auth\FacilityScope;
use App\Shared\Errors\ErrorCode;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

final class AuthController
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
        ]);

        $user->forceFill([
            'public_id' => Str::uuid(),
            'facility_id' => null,
            'is_active' => true,
        ])->save();

        app(PermissionRegistrar::class)->setPermissionsTeamId(
            FacilityScope::of($user->facility_id)
        );

        $user->assignRole(RoleEnum::DONOR->value);

        $token = $user->createToken('api')->plainTextToken;

        return ApiResponse::success($this->tokenPayload($user, $token));
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password) || ! $user->is_active) {
            return ApiResponse::error(
                ErrorCode::UNAUTHENTICATED,
                'Invalid email or password',
                [],
                401
            );
        }

        $token = $user->createToken('api')->plainTextToken;

        return ApiResponse::success($this->tokenPayload($user, $token));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(['message' => 'Logged out successfully']);

    }

    /**
     * @return array<string, mixed>
     */
    private function tokenPayload(User $user, string $plainTextToken): array
    {
        return [
            'token' => $plainTextToken,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->getRoleNames()->first(),
            ],
        ];
    }
}
