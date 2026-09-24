<?php

use App\Broadcasting\ChatChannelAuthorizer;
use Illuminate\Support\Facades\Broadcast;

$guards = ['guards' => ['api']];

Broadcast::channel('chat.conversation.{conversationId}', function ($user, $conversationId) {
    return app(ChatChannelAuthorizer::class)->conversation($user, (int) $conversationId);
}, $guards);

Broadcast::channel('chat.company.{companyId}', function ($user, $companyId) {
    return app(ChatChannelAuthorizer::class)->company($user, (int) $companyId);
}, $guards);

Broadcast::channel('chat.online', function ($user) {
    return app(ChatChannelAuthorizer::class)->online($user);
}, $guards);
