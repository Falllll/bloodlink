<?php

namespace App\Providers;

use App\Models\AboRhDetermination;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\BloodBatch;
use App\Models\BloodRequest;
use App\Models\BloodRequestAllocation;
use App\Models\Deferral;
use App\Models\DeferralReason;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\DonorConsent;
use App\Models\DonorScreening;
use App\Models\Facility;
use App\Models\StockTransfer;
use App\Models\StorageLocation;
use App\Models\TtiTestResult;
use App\Models\User;
use App\Modules\Donor\Domain\EligibilityEngine;
use App\Modules\Donor\Domain\Events\DonationCompleted;
use App\Modules\Donor\Domain\Events\DonorPermanentlyDeferred;
use App\Modules\Donor\Infrastructure\Policies\DonorPolicy;
use App\Modules\Donor\Infrastructure\RecordPermanentDeferral;
use App\Modules\Identity\Infrastructure\Auditing\AuditObserver;
use App\Modules\Identity\Infrastructure\Policies\AuditLogPolicy;
use App\Modules\Identity\Infrastructure\Policies\FacilityPolicy;
use App\Modules\Inventory\Application\AssignStorageProfile;
use App\Modules\Inventory\Application\Listeners\CreateQuarantinedUnit;
use App\Modules\Inventory\Application\Listeners\MarkUnitReserved;
use App\Modules\Inventory\Application\Listeners\ReturnReservedUnitToStock;
use App\Modules\Inventory\Domain\ComponentExpiryPolicy;
use App\Modules\Inventory\Infrastructure\BloodBatchEventRecorder;
use App\Modules\Inventory\Infrastructure\Policies\BloodBatchPolicy;
use App\Modules\Inventory\Infrastructure\Policies\StockTransferPolicy;
use App\Modules\Inventory\Infrastructure\Policies\StorageLocationPolicy;
use App\Modules\Request\Domain\Events\UnitReservationLifted;
use App\Modules\Request\Domain\Events\UnitReservationPlaced;
use App\Modules\Request\Infrastructure\Policies\BloodRequestPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EligibilityEngine::class, fn (): EligibilityEngine => EligibilityEngine::who());
        $this->app->bind(ComponentExpiryPolicy::class, AssignStorageProfile::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(
            (int) config('security.rate_limit.per_minute')
        )->by(Auth::id() ?? $request->ip()));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(
            (int) config('security.rate_limit.auth_per_minute')
        )->by($request->ip()));

        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());
        Model::preventAccessingMissingAttributes(! app()->isProduction());

        Gate::policy(Facility::class, FacilityPolicy::class);
        Gate::policy(Donor::class, DonorPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(BloodBatch::class, BloodBatchPolicy::class);
        Gate::policy(StorageLocation::class, StorageLocationPolicy::class);
        Gate::policy(StockTransfer::class, StockTransferPolicy::class);
        Gate::policy(BloodRequest::class, BloodRequestPolicy::class);

        foreach ([Facility::class, Donor::class, BloodBatch::class, User::class, DonorConsent::class, Deferral::class, DeferralReason::class, DonorScreening::class, Appointment::class, Donation::class, TtiTestResult::class, AboRhDetermination::class, StorageLocation::class, StockTransfer::class, BloodRequest::class, BloodRequestAllocation::class] as $model) {
            $model::observe(AuditObserver::class);
        }

        // Peristiwa status unit (Kartu 260): hanya BloodBatch, jadi di luar loop.
        BloodBatch::observe(BloodBatchEventRecorder::class);

        Event::listen(DonationCompleted::class, CreateQuarantinedUnit::class);
        Event::listen(DonorPermanentlyDeferred::class, RecordPermanentDeferral::class);
        // Reservasi (kartu 330): sinkron, di transaksi pemesanan/pelepasan.
        Event::listen(UnitReservationPlaced::class, MarkUnitReserved::class);
        Event::listen(UnitReservationLifted::class, ReturnReservedUnitToStock::class);

        Event::listen(Looping::class, function () {
            static $last = 0;

            if (time() - $last < 30) {
                return;
            }

            $last = time();
            Cache::put('worker:heartbeat', time(), 120);
        });
    }
}
