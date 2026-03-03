<?php

namespace App\Providers;

use App\Contracts\SmsProviderInterface;
use App\Services\NullSmsService;
use App\Services\TwilioSmsService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SmsProviderInterface::class, function () {
            $sid = config('services.twilio.sid');
            $token = config('services.twilio.token');
            $from = config('services.twilio.from');

            return ($sid && $token && $from)
                ? $this->app->make(TwilioSmsService::class)
                : $this->app->make(NullSmsService::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
