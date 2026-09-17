<?php

namespace Tests\Feature;

use App\Events\ChatMessageDeleted;
use App\Events\ChatMessageSent;
use App\Events\ChatMessagesRead;
use App\Events\ChatUserTyping;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Support\ChatChannels;
use App\Support\UserPresence;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithChat;
use Tests\TestCase;

class ChatRealtimeTest extends TestCase
{
    use InteractsWithChat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMinimalChatSchema();
        config(['presence.online_status_timeout' => 120]);
        $this->useReverbBroadcaster();
    }

    public function test_message_is_broadcast_only_after_successful_persistence(): void
    {
        Event::fake([ChatMessageSent::class]);

        [$userA, $companyA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($user1))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Do you have 4 JBL speakers available?',
            ])
            ->assertCreated();

        $this->assertSame(1, ChatMessage::count());
        $stored = ChatMessage::first();

        Event::assertDispatched(ChatMessageSent::class, function (ChatMessageSent $event) use ($stored, $user1, $companyA, $companyB, $conversationId) {
            $payload = $event->broadcastWith();
            $channels = collect($event->broadcastOn())->map(fn ($channel) => (string) $channel)->all();

            return (int) $event->message->id === (int) $stored->id
                && (int) $event->conversation->id === (int) $conversationId
                && (int) $payload['message_id'] === (int) $stored->id
                && (int) $payload['sender_user_id'] === (int) $user1->id
                && $payload['sender_user_name'] === 'User 1'
                && (int) $payload['sender_company_id'] === (int) $companyB->id
                && $payload['message'] === 'Do you have 4 JBL speakers available?'
                && $payload['message_type'] === 'text'
                && $payload['sender_company_name'] === 'Company B'
                && $payload['preview'] === 'Do you have 4 JBL speakers available?'
                && $payload['notification']['sender_name'] === 'User 1'
                && $payload['notification']['sender_company_name'] === 'Company B'
                && (int) $payload['notification']['conversation_id'] === (int) $conversationId
                && in_array('presence-'.ChatChannels::conversation((int) $conversationId), $channels, true)
                && in_array('private-'.ChatChannels::company((int) $companyA->id), $channels, true)
                && in_array('private-'.ChatChannels::company((int) $companyB->id), $channels, true)
                && $event instanceof ShouldBroadcastNow;
        });
    }

    public function test_failed_message_does_not_broadcast(): void
    {
        Event::fake([ChatMessageSent::class]);

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => '',
            ])
            ->assertStatus(422);

        $this->assertSame(0, ChatMessage::count());
        Event::assertNotDispatched(ChatMessageSent::class);
    }

    public function test_unauthorized_send_does_not_broadcast(): void
    {
        Event::fake([ChatMessageSent::class]);

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        [$userC] = $this->createCompanyUsers('Company C', 'user', ['Carol C']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userC))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'I should not be here',
            ])
            ->assertStatus(403);

        $this->assertSame(0, ChatMessage::count());
        Event::assertNotDispatched(ChatMessageSent::class);
    }

    public function test_all_authorized_company_users_can_subscribe_to_conversation_channel(): void
    {
        [$userA, $companyA, $userB] = $this->createCompanyUsers('Company A', 'user', ['Alice A', 'Bob A']);
        [$user1, $companyB, $user2] = $this->createCompanyUsers('Company B', 'provider', ['User 1', 'User 2']);
        [$userC] = $this->createCompanyUsers('Company C', 'user', ['Carol C']);
        $conversationId = $this->openConversation($userA, $companyB->id);
        $channel = 'presence-'.ChatChannels::conversation($conversationId);

        foreach ([$userA, $userB, $user1, $user2] as $user) {
            $response = $this->authorizeChannel($user, $channel);
            $response->assertOk()->assertJsonStructure(['auth', 'channel_data']);

            $channelData = json_decode($response->json('channel_data'), true);
            $this->assertSame((string) $user->id, (string) $channelData['user_id']);
            $this->assertSame((int) $user->id, (int) $channelData['user_info']['user_id']);
            $this->assertSame((int) $user->company_id, (int) $channelData['user_info']['company_id']);
            $this->assertArrayNotHasKey('email', $channelData['user_info']);
            $this->assertArrayNotHasKey('phone', $channelData['user_info']);
        }

        $this->authorizeChannel($userC, $channel)->assertForbidden();
        $this->assertSame((int) $companyA->id, (int) $userA->company_id);
        $this->assertSame((int) $companyB->id, (int) $user1->company_id);
    }

    public function test_company_channel_cannot_be_spoofed_by_another_company(): void
    {
        [$userA, $companyA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$userC] = $this->createCompanyUsers('Company C', 'user', ['Carol C']);

        $this->authorizeChannel($userA, 'private-'.ChatChannels::company((int) $companyA->id))
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->authorizeChannel($userC, 'private-'.ChatChannels::company((int) $companyA->id))
            ->assertForbidden();
    }

    public function test_typing_is_broadcast_to_authorized_users_and_not_persisted(): void
    {
        Event::fake([ChatUserTyping::class]);

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        [$userC] = $this->createCompanyUsers('Company C', 'user', ['Carol C']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/typing", [
                'is_typing' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Event::assertDispatched(ChatUserTyping::class, function (ChatUserTyping $event) use ($userA, $conversationId) {
            $payload = $event->broadcastWith();
            $channels = collect($event->broadcastOn())->map(fn ($channel) => (string) $channel)->all();

            return (int) $event->conversation->id === (int) $conversationId
                && (int) $payload['user_id'] === (int) $userA->id
                && $payload['user_name'] === 'Alice A'
                && $payload['is_typing'] === true
                && $channels === ['presence-'.ChatChannels::conversation((int) $conversationId)];
        });

        $this->assertSame(0, ChatMessage::count());

        Event::fake([ChatUserTyping::class]);

        $this->withToken($this->tokenFor($userC))
            ->postJson("/api/chat/conversations/{$conversationId}/typing", [
                'is_typing' => true,
            ])
            ->assertStatus(403);

        Event::assertNotDispatched(ChatUserTyping::class);
    }

    public function test_presence_join_exposes_safe_fields_and_heartbeats_last_seen_at(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$userB] = $this->createCompanyUsers('Company B', 'provider', ['User B']);
        $this->assertNull($userA->fresh()->last_seen_at);

        $response = $this->authorizeChannel($userA, 'presence-'.ChatChannels::online());
        $response->assertOk()->assertJsonStructure(['auth', 'channel_data']);

        $channelData = json_decode($response->json('channel_data'), true);
        $this->assertSame('Alice A', $channelData['user_info']['user_name']);
        $this->assertSame((int) $userA->company_id, (int) $channelData['user_info']['company_id']);
        $this->assertArrayNotHasKey('email', $channelData['user_info']);

        $this->assertNotNull($userA->fresh()->last_seen_at);
        $this->assertSame((int) $userB->company_id, (int) $userB->company_id);
    }

    public function test_company_stays_online_while_any_presence_member_is_connected(): void
    {
        $companyId = 10;
        $members = [
            ['user_id' => 1, 'user_name' => 'Alice A', 'company_id' => $companyId],
            ['user_id' => 2, 'user_name' => 'Bob A', 'company_id' => $companyId],
            ['user_id' => 3, 'user_name' => 'User 1', 'company_id' => 20],
        ];

        $this->assertTrue(UserPresence::companyIsOnlineFromPresenceMembers($members, $companyId));
        $this->assertTrue(UserPresence::companyIsOnlineFromPresenceMembers(array_slice($members, 1), $companyId));
        $this->assertFalse(UserPresence::companyIsOnlineFromPresenceMembers([
            ['user_id' => 3, 'user_name' => 'User 1', 'company_id' => 20],
        ], $companyId));
        $this->assertFalse(UserPresence::companyIsOnlineFromPresenceMembers([], $companyId));
    }

    public function test_unauthenticated_broadcast_auth_is_rejected(): void
    {
        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'presence-'.ChatChannels::online(),
        ])->assertStatus(401);
    }

    public function test_realtime_config_is_available_to_authenticated_users(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);

        $this->withToken($this->tokenFor($userA))
            ->getJson('/api/chat/realtime-config')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.broadcaster', 'reverb')
            ->assertJsonPath('data.auth_endpoint', '/api/broadcasting/auth')
            ->assertJsonPath('data.events.message_sent', 'chat.message.sent')
            ->assertJsonPath('data.events.message_deleted', 'chat.message.deleted')
            ->assertJsonPath('data.events.messages_read', 'chat.messages.read')
            ->assertJsonPath('data.events.user_typing', 'chat.user.typing');
    }

    public function test_broadcast_payload_does_not_include_sensitive_fields(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversation = ChatConversation::create([
            'company_a_id' => $userA->company_id,
            'company_b_id' => $companyB->id,
            'created_by_user_id' => $userA->id,
            'pair_key' => ChatConversation::pairKeyFor((int) $userA->company_id, (int) $companyB->id),
        ]);
        $message = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $user1->id,
            'sender_company_id' => $companyB->id,
            'message' => 'Hello',
            'message_type' => 'text',
        ]);

        $event = new ChatMessageSent(
            message: $message,
            conversation: $conversation,
            sender: $user1->load('profile'),
            senderCompanyId: (int) $companyB->id,
            recipientCompanyId: (int) $userA->company_id,
            recipientUserIds: [(int) $userA->id],
            defaultContactUserId: (int) $userA->id,
        );

        $payload = $event->broadcastWith();
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('recipient_user_ids', $payload);
        $this->assertArrayHasKey('notification', $payload);
        $this->assertArrayNotHasKey('email', $payload['notification']);
        $this->assertInstanceOf(PresenceChannel::class, $event->broadcastOn()[0]);
        $this->assertInstanceOf(PrivateChannel::class, $event->broadcastOn()[1]);
    }

    public function test_message_deletion_is_broadcast_to_authorized_channels(): void
    {
        Event::fake([ChatMessageSent::class, ChatMessageDeleted::class]);

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $sent = $this->withToken($this->tokenFor($user1))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Please ignore this',
            ]);
        $sent->assertCreated();
        $messageId = $sent->json('data.id');

        $this->withToken($this->tokenFor($user1))
            ->deleteJson("/api/chat/conversations/{$conversationId}/messages/{$messageId}")
            ->assertOk()
            ->assertJsonPath('data.is_deleted', true);

        Event::assertDispatched(ChatMessageDeleted::class, function (ChatMessageDeleted $event) use ($messageId, $conversationId, $user1) {
            $payload = $event->broadcastWith();
            $channels = collect($event->broadcastOn())->map(fn ($channel) => (string) $channel)->all();

            return (int) $payload['message_id'] === (int) $messageId
                && (int) $payload['conversation_id'] === (int) $conversationId
                && $payload['is_deleted'] === true
                && (int) $payload['deleted_by_user_id'] === (int) $user1->id
                && in_array('presence-'.ChatChannels::conversation((int) $conversationId), $channels, true)
                && $event instanceof ShouldBroadcastNow;
        });
    }

    public function test_mark_read_broadcasts_per_user_read_state(): void
    {
        Event::fake([ChatMessageSent::class, ChatMessagesRead::class]);

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($user1))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Hello',
            ])
            ->assertCreated();

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/read")
            ->assertOk();

        Event::assertDispatched(ChatMessagesRead::class, function (ChatMessagesRead $event) use ($userA, $conversationId) {
            $payload = $event->broadcastWith();

            return (int) $payload['conversation_id'] === (int) $conversationId
                && (int) $payload['reader_user_id'] === (int) $userA->id
                && $payload['reader_user_name'] === 'Alice A'
                && $payload['unread_count'] === 0;
        });
    }

    public function test_company_c_cannot_subscribe_to_another_companies_conversation_channel(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        [$userC] = $this->createCompanyUsers('Company C', 'user', ['Carol C']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->authorizeChannel($userC, 'presence-'.ChatChannels::conversation($conversationId))
            ->assertForbidden();
    }

    private function useReverbBroadcaster(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb',
                'key' => 'psm-test-key',
                'secret' => 'psm-test-secret',
                'app_id' => 'psm-test',
                'options' => [
                    'host' => '127.0.0.1',
                    'port' => 8080,
                    'scheme' => 'http',
                    'useTLS' => false,
                ],
            ],
        ]);

        Broadcast::purge();
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');
    }

    private function authorizeChannel(User $user, string $channelName)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokenFor($user))
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => $channelName,
            ]);
    }
}
