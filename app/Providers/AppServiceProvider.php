<?php

namespace App\Providers;

use App\Contracts\SmsProvider;
use App\Listeners\LogEmailSending;
use App\Listeners\LogEmailSent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Equipment;
use App\Observers\EquipmentObserver;
use App\Policies\ChatConversationPolicy;
use App\Policies\ChatMessagePolicy;
use App\Services\TextMagicService;
use App\Services\TwilioService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
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
        Equipment::observe(EquipmentObserver::class);

        Gate::policy(ChatConversation::class, ChatConversationPolicy::class);
        Gate::policy(ChatMessage::class, ChatMessagePolicy::class);

        Event::listen(MessageSending::class, LogEmailSending::class);
        Event::listen(MessageSent::class, LogEmailSent::class);
    }
}
