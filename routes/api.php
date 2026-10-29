<?php

use App\Modules\Donor\Http\Controllers\AppointmentController;
use App\Modules\Donor\Http\Controllers\AppointmentScheduleController;
use App\Modules\Donor\Http\Controllers\DeferralController;
use App\Modules\Donor\Http\Controllers\DeferralReasonController;
use App\Modules\Donor\Http\Controllers\DonationController;
use App\Modules\Donor\Http\Controllers\DonorConsentController;
use App\Modules\Donor\Http\Controllers\DonorFlagController;
use App\Modules\Donor\Http\Controllers\DonorMergeController;
use App\Modules\Donor\Http\Controllers\DonorProfileController;
use App\Modules\Donor\Http\Controllers\DonorScreeningController;
use App\Modules\Donor\Http\Controllers\EligibilitySelfCheckController;
use App\Modules\Donor\Http\Controllers\MeAppointmentController;
use App\Modules\Donor\Http\Controllers\MeDonorController;
use App\Modules\Identity\Http\Controllers\AuditLogController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\FacilityController;
use App\Modules\Identity\Http\Controllers\PasswordController;
use App\Modules\Identity\Http\Controllers\StaffController;
use App\Modules\Inventory\Http\Controllers\BloodBatchBarcodeController;
use App\Modules\Inventory\Http\Controllers\BloodBatchController;
use App\Modules\Inventory\Http\Controllers\ComponentSeparationController;
use App\Modules\Inventory\Http\Controllers\InventoryReportController;
use App\Modules\Inventory\Http\Controllers\LabTestController;
use App\Modules\Inventory\Http\Controllers\StockTransferController;
use App\Modules\Inventory\Http\Controllers\StorageLocationController;
use App\Modules\Inventory\Http\Controllers\UnitReleaseController;
use App\Modules\Notification\Domain\NotificationCategory;
use App\Modules\Notification\Http\Controllers\DeviceTokenController;
use App\Modules\Notification\Http\Controllers\NotificationHistoryController;
use App\Modules\Notification\Http\Controllers\NotificationPreferenceController;
use App\Modules\Request\Http\Controllers\BloodRequestController;
use App\Modules\Request\Http\Controllers\CompatibilityController;
use App\Modules\Request\Http\Controllers\DonorCampaignController;
use App\Modules\Request\Http\Controllers\RequestReportController;
use App\Modules\Request\Http\Controllers\TransfusionController;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\EnsureIdempotency;
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
    // Kartu 485. Dua limiter: `auth` per IP dan `forgot-password` per alamat email.
    Route::post('/forgot-password', [PasswordController::class, 'forgot'])->middleware(['throttle:auth', 'throttle:forgot-password'])->name('password.forgot');
    Route::post('/reset-password', [PasswordController::class, 'reset'])->middleware('throttle:auth')->name('password.reset');
    Route::post('/change-password', [PasswordController::class, 'change'])->middleware(['auth:sanctum', 'throttle:auth'])->name('password.change');
});

Route::middleware('auth:sanctum')->prefix('me')->name('me.')->group(function (): void {
    Route::get('/donor', [MeDonorController::class, 'show'])->name('donor.show');
    Route::get('/donor/donations', [MeDonorController::class, 'donations'])->name('donor.donations.index');
    Route::get('/donor/eligibility', [MeDonorController::class, 'eligibility'])->name('donor.eligibility');
    Route::get('/donor/appointments', [MeAppointmentController::class, 'index'])->name('donor.appointments.index');
    Route::post('/donor/appointments', [MeAppointmentController::class, 'store'])->name('donor.appointments.store');
    Route::patch('/donor/appointments/{appointment}/status', [MeAppointmentController::class, 'cancel'])->whereUuid('appointment')->name('donor.appointments.cancel');
    Route::post('/devices', [DeviceTokenController::class, 'store'])->name('devices.store');
    Route::delete('/devices/{deviceToken}', [DeviceTokenController::class, 'destroy'])->whereUuid('deviceToken')->name('devices.destroy');
    // Kartu 489. Tanpa id di URL: riwayat orang lain tidak punya alamat.
    Route::get('/notifications', [NotificationHistoryController::class, 'index'])->name('notifications.index');
    // Kartu 488. Kategori wajib (account_security, facility_operations) 404 di sini.
    Route::get('/notification-preferences', [NotificationPreferenceController::class, 'index'])->name('notification-preferences.index');
    Route::patch('/notification-preferences/{category}', [NotificationPreferenceController::class, 'update'])
        ->whereIn('category', array_map(fn (NotificationCategory $c): string => $c->value, NotificationCategory::optional()))
        ->name('notification-preferences.update');
    Route::get('/campaign-invitations', [DonorCampaignController::class, 'myInvitations'])->name('campaign-invitations.index');
    Route::post('/campaign-invitations/{contact}/respond', [DonorCampaignController::class, 'respond'])->whereUuid('contact')->name('campaign-invitations.respond');
});

Route::get('/ping', fn () => ['pong' => true]);

Route::post('/eligibility/self-check', EligibilitySelfCheckController::class)
    ->withoutMiddleware(EnsureIdempotency::class)
    ->middleware('throttle:20,1')
    ->name('eligibility.self-check');

Route::middleware(['auth:sanctum', 'facility.context'])->group(function (): void {
    Route::get('/blood-batches', [BloodBatchController::class, 'index'])->name('blood-batches.index');
    Route::post('/blood-batches', [BloodBatchController::class, 'store'])->name('blood-batches.store');
    Route::get('/blood-batches/{bloodBatch}', [BloodBatchController::class, 'show'])->name('blood-batches.show');
    Route::patch('/blood-batches/{bloodBatch}/status', [BloodBatchController::class, 'transition'])->name('blood-batches.transition');
    Route::get('/blood-batches/{publicId}/barcode', BloodBatchBarcodeController::class)->whereUuid('publicId')->name('blood-batches.barcode');
    Route::post('/blood-batches/{bloodBatch}/components', ComponentSeparationController::class)->name('blood-batches.components.store');
    Route::get('/blood-batches/{bloodBatch}/lab-results', [LabTestController::class, 'index'])->name('blood-batches.lab-results.index');
    Route::post('/blood-batches/{bloodBatch}/tti-results', [LabTestController::class, 'storeTtiResult'])->name('blood-batches.tti-results.store');
    Route::post('/blood-batches/{bloodBatch}/abo-rh', [LabTestController::class, 'storeAboRh'])->name('blood-batches.abo-rh.store');
    Route::post('/blood-batches/{bloodBatch}/release', UnitReleaseController::class)->name('blood-batches.release');

    Route::get('/storage-locations', [StorageLocationController::class, 'index'])->name('storage-locations.index');
    Route::post('/storage-locations', [StorageLocationController::class, 'store'])->name('storage-locations.store');
    Route::get('/storage-locations/{storageLocation}/temperature-readings', [StorageLocationController::class, 'readings'])->name('storage-locations.readings.index');
    Route::post('/storage-locations/{storageLocation}/temperature-readings', [StorageLocationController::class, 'recordReading'])->name('storage-locations.readings.store');

    Route::get('/stock-transfers', [StockTransferController::class, 'index'])->name('stock-transfers.index');
    Route::post('/stock-transfers', [StockTransferController::class, 'store'])->name('stock-transfers.store');
    Route::patch('/stock-transfers/{stockTransfer}/decision', [StockTransferController::class, 'decide'])->whereUuid('stockTransfer')->name('stock-transfers.decide');
    Route::post('/stock-transfers/{stockTransfer}/ship', [StockTransferController::class, 'ship'])->whereUuid('stockTransfer')->name('stock-transfers.ship');
    Route::post('/stock-transfers/{stockTransfer}/receive', [StockTransferController::class, 'receive'])->whereUuid('stockTransfer')->name('stock-transfers.receive');
    Route::post('/stock-transfers/{stockTransfer}/cancel', [StockTransferController::class, 'cancel'])->whereUuid('stockTransfer')->name('stock-transfers.cancel');

    Route::get('/blood-requests', [BloodRequestController::class, 'index'])->name('blood-requests.index');
    Route::post('/blood-requests', [BloodRequestController::class, 'store'])->name('blood-requests.store');
    Route::post('/blood-requests/urgent', [BloodRequestController::class, 'storeUrgent'])
        ->middleware('throttle:urgent-requests')
        ->name('blood-requests.store-urgent');
    Route::post('/blood-requests/{bloodRequest}/cancel', [BloodRequestController::class, 'cancel'])->name('blood-requests.cancel');
    Route::get('/blood-requests/{bloodRequest}/candidates', [BloodRequestController::class, 'candidates'])->name('blood-requests.candidates');
    Route::get('/blood-requests/{bloodRequest}/donor-candidates', [BloodRequestController::class, 'donorCandidates'])->name('blood-requests.donor-candidates');
    Route::post('/blood-requests/{bloodRequest}/campaign', [DonorCampaignController::class, 'start'])->name('blood-requests.campaign.start');
    Route::get('/blood-requests/{bloodRequest}/campaign', [DonorCampaignController::class, 'show'])->name('blood-requests.campaign.show');
    Route::post('/campaigns/{campaign}/stop', [DonorCampaignController::class, 'stop'])->name('campaigns.stop');
    Route::post('/blood-requests/{bloodRequest}/allocations', [BloodRequestController::class, 'allocate'])->name('blood-requests.allocate');
    Route::delete('/allocations/{allocation}', [BloodRequestController::class, 'releaseAllocation'])->name('allocations.release');
    Route::post('/blood-requests/{bloodRequest}/reservations', [BloodRequestController::class, 'reserve'])->name('blood-requests.reserve');
    Route::delete('/allocations/{allocation}/reservation', [BloodRequestController::class, 'releaseReservation'])->name('allocations.reservation.release');
    Route::post('/allocations/{allocation}/crossmatches', [BloodRequestController::class, 'recordCrossmatch'])->name('allocations.crossmatches.store');
    Route::post('/allocations/{allocation}/issue', [BloodRequestController::class, 'issue'])->name('allocations.issue');
    Route::post('/allocations/{allocation}/transfusion', [TransfusionController::class, 'store'])->name('allocations.transfusion.store');
    Route::post('/transfusions/{transfusion}/reactions', [TransfusionController::class, 'reportReaction'])->name('transfusions.reactions.store');
    Route::get('/transfusions/{transfusion}', [TransfusionController::class, 'show'])->name('transfusions.show');
    Route::get('/compatibility', CompatibilityController::class)->name('compatibility.show');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

    Route::get('/reports/wastage', [InventoryReportController::class, 'wastage'])->name('reports.wastage');
    Route::get('/reports/stock-trend', [InventoryReportController::class, 'stockTrend'])->name('reports.stock-trend');
    Route::get('/reports/crossmatch-transfusion', [RequestReportController::class, 'crossmatchTransfusion'])->name('reports.crossmatch-transfusion');

    Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
    Route::post('/donors/{donor}/appointments', [AppointmentController::class, 'store'])->name('donors.appointments.store');
    Route::patch('/appointments/{appointment}/status', [AppointmentController::class, 'transition'])->name('appointments.transition');
    Route::post('/appointments/{appointment}/donation', [DonationController::class, 'store'])->name('appointments.donation.store');

    Route::get('/facilities', [FacilityController::class, 'index'])->name('facilities.index');
    Route::post('/facilities', [FacilityController::class, 'store'])->name('facilities.store');
    Route::get('/facilities/{facility}', [FacilityController::class, 'show'])->name('facilities.show');
    Route::patch('/facilities/{facility}', [FacilityController::class, 'update'])->name('facilities.update');
    Route::patch('/facilities/{facility}/deactivate', [FacilityController::class, 'deactivate'])->name('facilities.deactivate');
    Route::post('/facilities/{facility}/activate', [FacilityController::class, 'activate'])->name('facilities.activate');
    Route::post('/facilities/{facility}/verify', [FacilityController::class, 'verify'])->name('facilities.verify');

    // Pola jadwal & kapasitas slot appointment (kartu 503).
    Route::get('/facilities/{facility}/appointment-schedule', [AppointmentScheduleController::class, 'show'])->name('facilities.appointment-schedule.show');
    Route::put('/facilities/{facility}/appointment-schedule', [AppointmentScheduleController::class, 'replace'])->name('facilities.appointment-schedule.replace');
    Route::post('/facilities/{facility}/appointment-closures', [AppointmentScheduleController::class, 'addClosure'])->name('facilities.appointment-closures.store');
    Route::delete('/facilities/{facility}/appointment-closures/{date}', [AppointmentScheduleController::class, 'removeClosure'])->where('date', '\d{4}-\d{2}-\d{2}')->name('facilities.appointment-closures.destroy');
    Route::get('/facilities/{facility}/appointment-slots', [AppointmentScheduleController::class, 'slots'])->name('facilities.appointment-slots');

    // Manajemen akun staf (kartu 487). Diikat lewat public_id, tidak pernah id numerik.
    Route::get('/users', [StaffController::class, 'index'])->name('users.index');
    Route::post('/users', [StaffController::class, 'store'])->name('users.store');
    Route::get('/users/{user:public_id}', [StaffController::class, 'show'])->whereUuid('user')->name('users.show');
    Route::put('/users/{user:public_id}/assignment', [StaffController::class, 'assign'])->whereUuid('user')->name('users.assignment');
    Route::patch('/users/{user:public_id}/deactivate', [StaffController::class, 'deactivate'])->whereUuid('user')->name('users.deactivate');
    Route::post('/users/{user:public_id}/activate', [StaffController::class, 'activate'])->whereUuid('user')->name('users.activate');
    Route::get('/donors', [DonorProfileController::class, 'index'])->name('donors.index');
    Route::post('/donors/merge', DonorMergeController::class)->name('donors.merge');
    Route::get('/deferral-reasons', DeferralReasonController::class)->name('deferral-reasons.index');
    Route::get('/donors/{donor}/deferrals', [DeferralController::class, 'index'])->name('donors.deferrals.index');
    Route::post('/donors/{donor}/deferrals', [DeferralController::class, 'store'])->name('donors.deferrals.store');
    Route::patch('/deferrals/{deferral}/lift', [DeferralController::class, 'lift'])->name('deferrals.lift');
    Route::get('/donors/{donor}/flags', [DonorFlagController::class, 'index'])->name('donors.flags.index');
    Route::post('/donors/{donor}/flags', [DonorFlagController::class, 'store'])->name('donors.flags.store');
    Route::post('/donor-flags/{donorFlag}/lift', [DonorFlagController::class, 'lift'])->name('donor-flags.lift');
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
