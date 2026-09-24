<?php

use App\Models\BloodBatch;
use App\Models\User;
use App\Modules\Donor\Http\Controllers\AppointmentController;
use App\Modules\Donor\Http\Controllers\DonationController;
use App\Modules\Donor\Http\Controllers\DonorConsentController;
use App\Modules\Donor\Http\Controllers\DonorMergeController;
use App\Modules\Donor\Http\Controllers\DonorProfileController;
use App\Modules\Donor\Http\Controllers\DonorScreeningController;
use App\Modules\Donor\Http\Controllers\EligibilitySelfCheckController;
use App\Modules\Identity\Http\Controllers\AuditLogController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\FacilityController;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\EnsureIdempotency;
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
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth')->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth')->name('login');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum')->name('logout');
});

Route::get('/ping', fn () => ['pong' => true]);

Route::post('/eligibility/self-check', EligibilitySelfCheckController::class)
    ->withoutMiddleware(EnsureIdempotency::class)
    ->middleware('throttle:20,1')
    ->name('eligibility.self-check');

Route::middleware(['auth:sanctum', 'facility.context'])->group(function (): void {
    Route::get('/blood-batches', function (Request $request) {
        /** @var User|null $user */
        $user = $request->user();

        return ApiResponse::paginated(
            BloodBatch::query()->visibleTo($user)->orderBy('id')->cursorPaginate(25)
        );
    })->name('blood-batches.index');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

    Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
    Route::post('/donors/{donor}/appointments', [AppointmentController::class, 'store'])->name('donors.appointments.store');
    Route::patch('/appointments/{appointment}/status', [AppointmentController::class, 'transition'])->name('appointments.transition');
    Route::post('/appointments/{appointment}/donation', [DonationController::class, 'store'])->name('appointments.donation.store');

    Route::get('/facilities', [FacilityController::class, 'index'])->name('facilities.index');
    Route::post('/facilities', [FacilityController::class, 'store'])->name('facilities.store');
    Route::get('/facilities/{facility}', [FacilityController::class, 'show'])->name('facilities.show');
    Route::patch('/facilities/{facility}', [FacilityController::class, 'update'])->name('facilities.update');
    Route::patch('/facilities/{facility}/deactivate', [FacilityController::class, 'deactivate'])->name('facilities.deactivate');
    Route::post('/donors/merge', DonorMergeController::class)->name('donors.merge');
    Route::post('/donors/{donor}/consents', [DonorConsentController::class, 'store'])->name('donors.consents.store');
    Route::get('/donors/{donor}', [DonorProfileController::class, 'show'])->name('donors.show');
    Route::patch('/donors/{donor}', [DonorProfileController::class, 'update'])->name('donors.update');
    Route::patch('/donors/{donor}/health-status', [DonorProfileController::class, 'updateHealthStatus'])->name('donors.health-status.update');
    Route::post('/donors/{donor}/screenings', [DonorScreeningController::class, 'store'])->name('donors.screenings.store');

    if (! app()->isProduction()) {
        Route::get('/_test/scope', fn () => ApiResponse::success([
            'permission_team_id' => app(PermissionRegistrar::class)->getPermissionsTeamId(),
        ]))->name('_test.scope');
    }
});
