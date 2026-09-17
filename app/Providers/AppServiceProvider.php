<?php

namespace App\Providers;

use App\Contracts\SmsProvider;
use App\Listeners\LogEmailSent;
use App\Listeners\LogEmailSending;
use App\Models\Equipment;
use App\Observers\EquipmentObserver;
use App\Services\TextMagicService;
use App\Services\TwilioService;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SmsProvider::class, function () {
            return config('services.sms.driver') === 'textmagic'
                ? new TextMagicService
                : new TwilioService;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // AdminLTE uses Bootstrap 4 — avoid Laravel's default Tailwind pagination
        // (Tailwind classes/SVGs are not loaded, which causes oversized chevrons).
        Paginator::useBootstrapFour();

        Equipment::observe(EquipmentObserver::class);

        Event::listen(MessageSending::class, LogEmailSending::class);
        Event::listen(MessageSent::class, LogEmailSent::class);
    }
}
