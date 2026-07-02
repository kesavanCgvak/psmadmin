<?php

namespace App\Providers;

use App\Contracts\SmsProvider;
use App\Listeners\LogEmailSent;
use App\Listeners\LogEmailSending;
use App\Services\TextMagicService;
use App\Services\TwilioService;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Events\MessageSending;
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
        Event::listen(MessageSending::class, LogEmailSending::class);
        Event::listen(MessageSent::class, LogEmailSent::class);
    }
}
