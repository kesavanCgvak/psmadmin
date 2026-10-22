<?php

namespace App\Providers;

use App\Contracts\SmsProvider;
use App\Http\Middleware\ProviderApiKeyMiddleware;
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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Pagination\Paginator;
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

        Gate::policy(ChatConversation::class, ChatConversationPolicy::class);
        Gate::policy(ChatMessage::class, ChatMessagePolicy::class);

        Event::listen(MessageSending::class, LogEmailSending::class);
        Event::listen(MessageSent::class, LogEmailSent::class);

        RateLimiter::for('support-session-start', function (Request $request) {
            $bucket = $request->user()?->id
                ? 'support-session-start:user:'.$request->user()->id
                : 'support-session-start:ip:'.$request->ip();

            return Limit::perMinute(10)->by($bucket)->response(function (Request $request, array $headers) {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many support session requests. Try again shortly.',
                ], 429, $headers);
            });
        });

        RateLimiter::for('support-session-end', function (Request $request) {
            $bucket = $request->user()?->id
                ? 'support-session-end:user:'.$request->user()->id
                : 'support-session-end:ip:'.$request->ip();

            return Limit::perMinute(30)->by($bucket)->response(function (Request $request, array $headers) {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many support session requests. Try again shortly.',
                ], 429, $headers);
            });
        });

        RateLimiter::for('partner-chat', function (Request $request) {
            $limit = max(1, (int) config('chat.partner_rate_limit_per_minute', 60));
            $company = $request->attributes->get('provider_company');
            $companyId = $company?->id ?? ProviderApiKeyMiddleware::providerCompanyId($request);
            $bucket = $companyId
                ? 'partner-chat:company:'.$companyId
                : 'partner-chat:ip:'.$request->ip();

            return Limit::perMinute($limit)->by($bucket)->response(function (Request $request, array $headers) {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many requests.',
                ], 429, $headers);
            });
        });
    }
}
