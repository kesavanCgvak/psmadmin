<?php

namespace Tests\Feature;

use App\Events\ChatMessageSent;
use App\Models\ChatConversation;
use App\Models\ChatConversationUserState;
use App\Models\ChatMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithChat;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    use InteractsWithChat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMinimalChatSchema();
        config(['presence.online_status_timeout' => 120]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_company_can_start_conversation_with_another_company(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);

        $response = $this->withToken($this->tokenFor($userA))
            ->postJson('/api/chat/conversations', ['company_id' => $companyB->id]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.other_company_id', $companyB->id)
            ->assertJsonPath('data.other_company_name', 'Company B')
            ->assertJsonPath('data.unread_count', 0);

        $this->assertSame(1, ChatConversation::count());
        $conversation = ChatConversation::first();
        $this->assertTrue($conversation->involvesCompany((int) $userA->company_id));
        $this->assertTrue($conversation->involvesCompany((int) $companyB->id));
        $this->assertSame((int) $userA->id, (int) $conversation->created_by_user_id);
    }

    public function test_existing_company_conversation_is_reused_from_either_side(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);

        $first = $this->withToken($this->tokenFor($userA))
            ->postJson('/api/chat/conversations', ['company_id' => $companyB->id]);
        $first->assertCreated();

        $second = $this->withToken($this->tokenFor($user1))
            ->postJson('/api/chat/conversations', ['company_id' => $userA->company_id]);

        $second->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('message', 'Existing conversation returned.');

        $this->assertSame(1, ChatConversation::count());
    }

    public function test_same_company_users_share_one_conversation(): void
    {
        [$userA, $companyA, $userB] = $this->createCompanyUsers('Company A', 'user', ['Alice A', 'Bob A']);
        [$user1, $companyB, $user2] = $this->createCompanyUsers('Company B', 'provider', ['User 1', 'User 2']);

        $created = $this->withToken($this->tokenFor($userA))
            ->postJson('/api/chat/conversations', ['company_id' => $companyB->id]);
        $created->assertCreated();
        $conversationId = $created->json('data.id');

        $this->withToken($this->tokenFor($userB))
            ->postJson('/api/chat/conversations', ['company_id' => $companyB->id])
            ->assertOk()
            ->assertJsonPath('data.id', $conversationId);

        $this->withToken($this->tokenFor($user1))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Do you have 4 JBL speakers available?',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sender_user_id', $user1->id)
            ->assertJsonPath('data.sender_company_id', $companyB->id)
            ->assertJsonPath('data.is_mine', true);

        $this->withToken($this->tokenFor($user2))
            ->getJson('/api/chat/conversations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $conversationId)
            ->assertJsonPath('data.0.last_message.sender_user_id', $user1->id);

        $history = $this->withToken($this->tokenFor($user2))
            ->getJson("/api/chat/conversations/{$conversationId}/messages");

        $history->assertOk()
            ->assertJsonPath('data.0.sender_user_id', $user1->id)
            ->assertJsonPath('data.0.sender_company_id', $companyB->id)
            ->assertJsonPath('data.0.is_mine', false);

        $this->assertSame(1, ChatConversation::count());
        $this->assertSame(1, ChatMessage::count());
        $this->assertSame((int) $companyA->id, (int) $userA->company_id);
    }

    public function test_cannot_start_conversation_with_own_company(): void
    {
        [$user, $company] = $this->createCompanyUsers('Solo Co', 'user', ['Solo User']);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/chat/conversations', ['company_id' => $company->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, ChatConversation::count());
    }

    public function test_cannot_create_conversation_with_missing_company(): void
    {
        [$user] = $this->createCompanyUsers('Solo Co', 'user', ['Solo User']);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/chat/conversations', ['company_id' => 999999])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_send_message_stores_sender_user_and_company_and_dispatches_event(): void
    {
        Event::fake([ChatMessageSent::class]);

        [$userA, $companyA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $response = $this->withToken($this->tokenFor($user1))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Do you have 4 JBL speakers available?',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.sender_user_id', $user1->id)
            ->assertJsonPath('data.sender_user_name', 'User 1')
            ->assertJsonPath('data.sender_company_id', $companyB->id)
            ->assertJsonPath('data.is_mine', true);

        $this->assertSame((int) $user1->id, (int) ChatMessage::first()->sender_user_id);
        $this->assertSame((int) $companyB->id, (int) ChatMessage::first()->sender_company_id);

        Event::assertDispatched(ChatMessageSent::class, function (ChatMessageSent $event) use ($userA, $user1, $companyA, $companyB, $conversationId) {
            return (int) $event->conversation->id === (int) $conversationId
                && (int) $event->sender->id === (int) $user1->id
                && (int) $event->senderCompanyId === (int) $companyB->id
                && (int) $event->recipientCompanyId === (int) $companyA->id
                && $event->recipientUserIds === [(int) $userA->id]
                && $event->defaultContactUserId === (int) $userA->id
                && $event->broadcastOn() !== [];
        });
    }

    public function test_empty_message_is_rejected(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => '',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, ChatMessage::count());
    }

    public function test_conversation_list_includes_company_last_message_unread_and_online_status(): void
    {
        Carbon::setTestNow('2026-09-16 12:00:00');

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB, $user2] = $this->createCompanyUsers('Company B', 'provider', ['User 1', 'User 2']);
        $this->setLastSeen($user2, now()->subSeconds(30));

        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($user1))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Yes, it is in stock.',
            ])
            ->assertCreated();

        $response = $this->withToken($this->tokenFor($userA))
            ->getJson('/api/chat/conversations');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $conversationId)
            ->assertJsonPath('data.0.other_company_id', $companyB->id)
            ->assertJsonPath('data.0.other_company_name', 'Company B')
            ->assertJsonPath('data.0.online_status', true)
            ->assertJsonPath('data.0.last_message.message', 'Yes, it is in stock.')
            ->assertJsonPath('data.0.last_message.is_deleted', false)
            ->assertJsonPath('data.0.unread_count', 1)
            ->assertJsonPath('data.0.archived', false)
            ->assertJsonPath('data.0.rental_job_id', null)
            ->assertJsonPath('meta.total_unread', 1);

        $this->assertIsBool($response->json('data.0.online_status'));
        $this->assertNotNull($response->json('data.0.other_company_logo'));
    }

    public function test_company_is_offline_when_no_user_was_seen_recently(): void
    {
        Carbon::setTestNow('2026-09-16 12:00:00');

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $this->setLastSeen($user1, now()->subSeconds(121));
        $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.online_status', false);
    }

    public function test_messages_are_paginated_recent_first_with_is_mine(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        foreach (['first', 'second', 'third'] as $body) {
            $this->withToken($this->tokenFor($userA))
                ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                    'message' => $body,
                ])
                ->assertCreated();
        }

        $response = $this->withToken($this->tokenFor($user1))
            ->getJson("/api/chat/conversations/{$conversationId}/messages?per_page=2&page=1");

        $response->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.total_pages', 2)
            ->assertJsonPath('data.0.message', 'third')
            ->assertJsonPath('data.0.is_mine', false)
            ->assertJsonPath('data.1.message', 'second');
    }

    public function test_read_state_is_per_user_not_per_company(): void
    {
        [$userA, $companyA, $userB] = $this->createCompanyUsers('Company A', 'user', ['Alice A', 'Bob A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($user1))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Hello from Company B',
            ])
            ->assertCreated();

        $this->withToken($this->tokenFor($userA))
            ->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.unread_count', 1);

        $this->withToken($this->tokenFor($userB))
            ->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.unread_count', 1);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/read")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->withToken($this->tokenFor($userA))
            ->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.unread_count', 0);

        $this->withToken($this->tokenFor($userB))
            ->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.unread_count', 1);

        $this->withToken($this->tokenFor($userB))
            ->postJson("/api/chat/conversations/{$conversationId}/read")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->assertSame(2, ChatConversationUserState::count());
        $this->assertSame((int) $companyA->id, (int) $userA->company_id);
    }

    public function test_outsider_company_cannot_access_conversation(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        [$userC] = $this->createCompanyUsers('Company C', 'user', ['Carol C']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Private company chat',
            ])
            ->assertCreated();

        $this->withToken($this->tokenFor($userC))
            ->getJson('/api/chat/conversations')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->withToken($this->tokenFor($userC))
            ->getJson("/api/chat/conversations/{$conversationId}/messages")
            ->assertStatus(403);

        $this->withToken($this->tokenFor($userC))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'I should not be here',
            ])
            ->assertStatus(403);

        $this->withToken($this->tokenFor($userC))
            ->postJson("/api/chat/conversations/{$conversationId}/read")
            ->assertStatus(403);

        $this->assertSame(1, ChatMessage::count());
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/chat/conversations')->assertStatus(401);
        $this->postJson('/api/chat/conversations', ['company_id' => 1])->assertStatus(401);
    }

    public function test_missing_conversation_returns_not_found(): void
    {
        [$user] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/chat/conversations/999999/messages')
            ->assertStatus(404);
    }

}
