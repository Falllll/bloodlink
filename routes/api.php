<?php

use App\Models\BloodBatch;
use App\Models\User;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

Route::get('/openapi.yaml', function (): Response {
    return response()->file(base_path('docs/api/openapi.yaml'), [
        'Content-Type' => 'application/yaml',
    ]);
})->name('openapi.spec');

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum')->name('logout');
});

Route::get('/ping', fn () => ['pong' => true]);

Route::middleware(['auth:sanctum', 'facility.context'])->group(function (): void {
    Route::get('/blood-batches', function (Request $request) {
        /** @var User|null $user */
        $user = $request->user();

        return ApiResponse::paginated(
            BloodBatch::query()->visibleTo($user)->orderBy('id')->cursorPaginate(25)
        );
    })->name('blood-batches.index');

    Route::get('/_test/scope', fn () => ApiResponse::success([
        'permission_team_id' => app(PermissionRegistrar::class)->getPermissionsTeamId(),
    ]));
});
