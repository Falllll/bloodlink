<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Modules\Donor\Domain\Events\DonationCompleted;
use App\Modules\Inventory\Application\Listeners\CreateQuarantinedUnit;

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
        Event::listen(DonationCompleted::class, CreateQuarantinedUnit::class);
    }
}
