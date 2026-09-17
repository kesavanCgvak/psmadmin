<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatConversationUserState;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithChat;
use Tests\TestCase;

class ChatPhase3Test extends TestCase
{
    use InteractsWithChat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMinimalChatSchema();
        config(['presence.online_status_timeout' => 120]);
    }

    public function test_archive_is_per_user_and_does_not_hide_conversation_for_teammates(): void
    {
        [$userA, $companyA, $userB] = $this->createCompanyUsers('Company A', 'user', ['Alice A', 'Bob A']);
        [, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/archive")
            ->assertOk()
            ->assertJsonPath('data.archived', true)
            ->assertJsonPath('data.id', $conversationId);

        $this->withToken($this->tokenFor($userA))
            ->getJson('/api/chat/conversations')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->withToken($this->tokenFor($userA))
            ->getJson('/api/chat/conversations?archived=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.archived', true);

        $this->withToken($this->tokenFor($userB))
            ->getJson('/api/chat/conversations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.archived', false);

        $this->assertSame((int) $companyA->id, (int) $userA->company_id);
        $this->assertSame(1, ChatConversationUserState::query()->whereNotNull('archived_at')->count());
    }

    public function test_incoming_message_unarchives_for_recipient_company_users_only(): void
    {
        [$userA, , $userB] = $this->createCompanyUsers('Company A', 'user', ['Alice A', 'Bob A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/archive")
            ->assertOk();
        $this->withToken($this->tokenFor($userB))
            ->postJson("/api/chat/conversations/{$conversationId}/archive")
            ->assertOk();

        $this->withToken($this->tokenFor($user1))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Are you still interested?',
            ])
            ->assertCreated();

        $this->withToken($this->tokenFor($userA))
            ->getJson('/api/chat/conversations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.archived', false)
            ->assertJsonPath('data.0.unread_count', 1);

        $this->withToken($this->tokenFor($userB))
            ->getJson('/api/chat/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.archived', false)
            ->assertJsonPath('data.0.unread_count', 1);
    }

    public function test_teammate_send_does_not_unarchive_another_users_thread(): void
    {
        [$userA, , $userB] = $this->createCompanyUsers('Company A', 'user', ['Alice A', 'Bob A']);
        [, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userB))
            ->postJson("/api/chat/conversations/{$conversationId}/archive")
            ->assertOk();

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Following up from our side',
            ])
            ->assertCreated();

        $this->withToken($this->tokenFor($userB))
            ->getJson('/api/chat/conversations')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->withToken($this->tokenFor($userB))
            ->postJson("/api/chat/conversations/{$conversationId}/unarchive")
            ->assertOk()
            ->assertJsonPath('data.archived', false);
    }

    public function test_sender_can_soft_delete_own_message_and_history_is_preserved(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $sent = $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Secret availability',
            ]);
        $sent->assertCreated();
        $messageId = $sent->json('data.id');

        $this->withToken($this->tokenFor($userA))
            ->deleteJson("/api/chat/conversations/{$conversationId}/messages/{$messageId}")
            ->assertOk()
            ->assertJsonPath('data.is_deleted', true)
            ->assertJsonPath('data.message', '')
            ->assertJsonPath('data.id', $messageId);

        $this->assertNotNull(ChatMessage::withTrashed()->find($messageId)?->deleted_at);
        $this->assertSame(1, ChatMessage::withTrashed()->count());
        $this->assertSame(0, ChatMessage::count());

        $this->withToken($this->tokenFor($user1))
            ->getJson("/api/chat/conversations/{$conversationId}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.is_deleted', true)
            ->assertJsonPath('data.0.message', '');
    }

    public function test_cannot_delete_another_users_message_or_outsider_conversation_message(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        [$userC] = $this->createCompanyUsers('Company C', 'user', ['Carol C']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $sent = $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Only Alice can delete this',
            ]);
        $messageId = $sent->json('data.id');

        $this->withToken($this->tokenFor($user1))
            ->deleteJson("/api/chat/conversations/{$conversationId}/messages/{$messageId}")
            ->assertStatus(403);

        $this->withToken($this->tokenFor($userC))
            ->deleteJson("/api/chat/conversations/{$conversationId}/messages/{$messageId}")
            ->assertStatus(403);

        $this->assertNull(ChatMessage::withTrashed()->find($messageId)?->deleted_at);
    }

    public function test_read_receipts_are_per_recipient_user(): void
    {
        [$userA, , $userB] = $this->createCompanyUsers('Company A', 'user', ['Alice A', 'Bob A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($user1))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Quote attached',
            ])
            ->assertCreated();

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/read")
            ->assertOk();

        $history = $this->withToken($this->tokenFor($user1))
            ->getJson("/api/chat/conversations/{$conversationId}/messages");

        $history->assertOk()
            ->assertJsonPath('data.0.status', ChatMessage::STATUS_READ)
            ->assertJsonPath('data.0.read_by.0.user_id', $userA->id)
            ->assertJsonPath('data.0.read_by.0.user_name', 'Alice A');

        $readByIds = collect($history->json('data.0.read_by'))->pluck('user_id')->all();
        $this->assertContains((int) $userA->id, $readByIds);
        $this->assertNotContains((int) $userB->id, $readByIds);

        $this->withToken($this->tokenFor($userA))
            ->getJson('/api/chat/unread-count')
            ->assertOk()
            ->assertJsonPath('data.total_unread', 0);

        $this->withToken($this->tokenFor($userB))
            ->getJson('/api/chat/unread-count')
            ->assertOk()
            ->assertJsonPath('data.total_unread', 1)
            ->assertJsonPath('data.conversations_with_unread', 1);
    }

    public function test_search_matches_company_name_message_text_and_sender_and_excludes_other_companies(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        [$userC, $companyC] = $this->createCompanyUsers('Company C', 'user', ['Carol C']);

        $abId = $this->openConversation($userA, $companyB->id);
        $this->openConversation($userC, $companyB->id);

        $this->withToken($this->tokenFor($user1))
            ->postJson("/api/chat/conversations/{$abId}/messages", [
                'message' => 'We have 4 JBL speakers in stock',
            ])
            ->assertCreated();

        $asA = $this->withToken($this->tokenFor($userA))
            ->getJson('/api/chat/search?q=JBL');
        $asA->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.messages.0.message', 'We have 4 JBL speakers in stock')
            ->assertJsonPath('data.messages.0.other_company_name', 'Company B');

        $byCompany = $this->withToken($this->tokenFor($userA))
            ->getJson('/api/chat/search?q='.urlencode('Company B'));
        $byCompany->assertOk();
        $this->assertGreaterThanOrEqual(1, count($byCompany->json('data.conversations')));
        $this->assertSame($abId, $byCompany->json('data.conversations.0.id'));

        $bySender = $this->withToken($this->tokenFor($userA))
            ->getJson('/api/chat/search?q='.urlencode('User 1'));
        $bySender->assertOk();
        $this->assertNotEmpty($bySender->json('data.messages'));

        $asC = $this->withToken($this->tokenFor($userC))
            ->getJson('/api/chat/search?q=JBL');
        $asC->assertOk()
            ->assertJsonPath('data.messages', []);

        $this->withToken($this->tokenFor($userC))
            ->getJson("/api/chat/conversations/{$abId}")
            ->assertStatus(403);

        $this->assertSame((int) $companyC->id, (int) $userC->company_id);
    }

    public function test_rental_job_conversations_are_separate_from_general_and_filterable(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);

        $jobId = DB::table('rental_jobs')->insertGetId([
            'user_id' => $userA->id,
            'name' => 'Festival PA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $generalId = $this->openConversation($userA, $companyB->id);
        $jobIdConversation = $this->openConversation($userA, $companyB->id, $jobId);

        $this->assertNotSame($generalId, $jobIdConversation);
        $this->assertSame(2, ChatConversation::count());
        $this->assertNull(ChatConversation::find($generalId)->rental_job_id);
        $this->assertSame($jobId, (int) ChatConversation::find($jobIdConversation)->rental_job_id);

        $this->withToken($this->tokenFor($userA))
            ->postJson('/api/chat/conversations', [
                'company_id' => $companyB->id,
                'rental_job_id' => $jobId,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $jobIdConversation)
            ->assertJsonPath('data.rental_job_id', $jobId)
            ->assertJsonPath('data.rental_job.name', 'Festival PA');

        $this->withToken($this->tokenFor($userA))
            ->getJson('/api/chat/conversations?rental_job_id='.$jobId)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $jobIdConversation);

        $this->withToken($this->tokenFor($userA))
            ->getJson('/api/chat/conversations?general_only=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $generalId)
            ->assertJsonPath('data.0.rental_job_id', null);
    }

    public function test_outsider_cannot_archive_or_mark_read_or_search_into_foreign_conversation(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        [$userC] = $this->createCompanyUsers('Company C', 'user', ['Carol C']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userC))
            ->postJson("/api/chat/conversations/{$conversationId}/archive")
            ->assertStatus(403);

        $this->withToken($this->tokenFor($userC))
            ->postJson("/api/chat/conversations/{$conversationId}/unarchive")
            ->assertStatus(403);

        $this->withToken($this->tokenFor($userC))
            ->postJson("/api/chat/conversations/{$conversationId}/read")
            ->assertStatus(403);

        $this->withToken($this->tokenFor($userC))
            ->getJson("/api/chat/conversations/{$conversationId}")
            ->assertStatus(403);
    }

    public function test_notification_settings_default_off_and_can_be_enabled_without_forcing_permission(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);

        $this->withToken($this->tokenFor($userA))
            ->getJson('/api/chat/notification-settings')
            ->assertOk()
            ->assertJsonPath('data.browser_notifications_enabled', false);

        $this->withToken($this->tokenFor($userA))
            ->putJson('/api/chat/notification-settings', [
                'browser_notifications_enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.browser_notifications_enabled', true);

        $this->withToken($this->tokenFor($userA))
            ->getJson('/api/chat/realtime-config')
            ->assertOk()
            ->assertJsonPath('data.browser_notifications.enabled', true)
            ->assertJsonPath('data.browser_notifications.permission_must_be_granted_in_browser', true);
    }

    public function test_deleted_messages_are_excluded_from_unread_counts(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $sent = $this->withToken($this->tokenFor($user1))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'This will be deleted',
            ]);
        $messageId = $sent->json('data.id');

        $this->withToken($this->tokenFor($userA))
            ->getJson('/api/chat/unread-count')
            ->assertJsonPath('data.total_unread', 1);

        $this->withToken($this->tokenFor($user1))
            ->deleteJson("/api/chat/conversations/{$conversationId}/messages/{$messageId}")
            ->assertOk();

        $this->withToken($this->tokenFor($userA))
            ->getJson('/api/chat/unread-count')
            ->assertJsonPath('data.total_unread', 0);
    }
}
