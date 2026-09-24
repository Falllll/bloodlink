<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\BloodBatch;
use App\Models\Deferral;
use App\Models\DeferralReason;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\DonorConsent;
use App\Models\DonorScreening;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Donor\Domain\Events\DonationCompleted;
use App\Modules\Donor\Infrastructure\Policies\DonorPolicy;
use App\Modules\Identity\Infrastructure\Auditing\AuditObserver;
use App\Modules\Identity\Infrastructure\Policies\AuditLogPolicy;
use App\Modules\Identity\Infrastructure\Policies\FacilityPolicy;
use App\Modules\Inventory\Application\Listeners\CreateQuarantinedUnit;
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
        //
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

        foreach ([Facility::class, Donor::class, BloodBatch::class, User::class, DonorConsent::class, Deferral::class, DeferralReason::class, DonorScreening::class, Appointment::class, Donation::class] as $model) {
            $model::observe(AuditObserver::class);
        }

        Event::listen(DonationCompleted::class, CreateQuarantinedUnit::class);

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
