<?php

namespace App\Providers;

use App\Models\BloodBatch;
use App\Models\Donor;
use App\Models\Facility;
use App\Modules\Donor\Domain\Events\DonationCompleted;
use App\Modules\Identity\Infrastructure\Auditing\AuditObserver;
use App\Modules\Identity\Infrastructure\Policies\FacilityPolicy;
use App\Modules\Inventory\Application\Listeners\CreateQuarantinedUnit;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(Facility::class, FacilityPolicy::class);

        foreach ([Facility::class, Donor::class, BloodBatch::class] as $model) {
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
