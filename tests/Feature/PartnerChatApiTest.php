<?php

namespace Tests\Feature;

use App\Events\ChatMessageSent;
use App\Jobs\ProcessChatMessageNotificationsJob;
use App\Models\ChatConversation;
use App\Models\ChatExternalConversation;
use App\Models\ChatExternalMessage;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\ProviderApiKey;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\ChatChannels;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithChat;
use Tests\TestCase;

class PartnerChatApiTest extends TestCase
{
    use InteractsWithChat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPartnerChatSchema();
        config([
            'presence.online_status_timeout' => 120,
            'chat.partner_rate_limit_per_minute' => 60,
        ]);
        Bus::fake([ProcessChatMessageNotificationsJob::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_inbound_message_is_stored_as_the_authenticated_company_default_contact(): void
    {
        Event::fake([ChatMessageSent::class]);
        Carbon::setTestNow('2026-10-12 12:00:00');

        [$renter, $renterCompany] = $this->createCompanyUsers('Renter Co', 'user', ['Rita Renter']);
        [$providerUser, $providerCompany] = $this->providerWithChatKey();
        $conversation = $this->conversationBetween($renter, $providerUser);
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        $response = $this->withToken($key)->postJson('/api/v1/partner/chat/messages', [
            'external_system' => 'rentman',
            'external_message_id' => 'external-message-123',
            'external_conversation_id' => 'external-conversation-456',
            'psm_conversation_id' => $conversation->id,
            'body' => 'Hello, we have received your rental request.',
            'external_created_at' => '2020-01-01T00:00:00Z',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Message sent.')
            ->assertJsonPath('data.conversation_id', $conversation->id)
            ->assertJsonPath('data.external_message_id', 'external-message-123')
            ->assertJsonPath('data.external_system', 'rentman');

        $message = ChatMessage::query()->first();
        $this->assertNotNull($message);
        $this->assertSame((int) $providerUser->id, (int) $message->sender_user_id);
        $this->assertSame((int) $providerCompany->id, (int) $message->sender_company_id);
        $this->assertSame('Hello, we have received your rental request.', $message->message);
        $this->assertSame('2026', $message->created_at?->format('Y'));

        $link = ChatExternalMessage::query()->first();
        $this->assertNotNull($link);
        $this->assertSame((int) $message->id, (int) $link->message_id);
        $this->assertSame((int) $providerCompany->id, (int) $link->company_id);
        $this->assertSame(
            Carbon::parse('2020-01-01T00:00:00Z')->utc()->format('Y-m-d H:i'),
            $link->external_created_at?->copy()->utc()->format('Y-m-d H:i')
        );
        $this->assertSame(1, ChatExternalConversation::query()->count());
        $this->assertSame((int) $conversation->id, (int) ChatExternalConversation::query()->first()->conversation_id);

        Event::assertDispatched(ChatMessageSent::class, function (ChatMessageSent $event) use ($renter, $providerUser, $providerCompany, $renterCompany, $conversation) {
            return (int) $event->conversation->id === (int) $conversation->id
                && (int) $event->sender->id === (int) $providerUser->id
                && (int) $event->senderCompanyId === (int) $providerCompany->id
                && (int) $event->recipientCompanyId === (int) $renterCompany->id
                && $event->recipientUserIds === [(int) $renter->id];
        });

        Bus::assertDispatched(ProcessChatMessageNotificationsJob::class, function (ProcessChatMessageNotificationsJob $job) use ($providerUser, $conversation, $message) {
            return $job->senderUserId === (int) $providerUser->id
                && $job->conversationId === (int) $conversation->id
                && $job->messageId === (int) $message->id;
        });

        $this->withToken($this->tokenFor($renter))
            ->getJson('/api/chat/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 1);

        $this->withToken($this->tokenFor($providerUser))
            ->getJson('/api/chat/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 0);

        $this->withToken($this->tokenFor($renter))
            ->postJson("/api/chat/conversations/{$conversation->id}/read")
            ->assertOk();

        $this->withToken($this->tokenFor($renter))
            ->getJson('/api/chat/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 0);
    }

    public function test_missing_invalid_revoked_and_expired_keys_are_rejected(): void
    {
        [$providerUser] = $this->providerWithChatKey();
        $plain = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);
        $payload = $this->payload(1);

        $this->postJson('/api/v1/partner/chat/messages', $payload)
            ->assertUnauthorized()
            ->assertJsonPath('message', 'API key is required.');

        $this->withToken('psm_pk_not-a-real-key')->postJson('/api/v1/partner/chat/messages', $payload)
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid API key.');

        $key = ProviderApiKey::query()->first();
        $key->update(['is_active' => false, 'revoked_at' => now()]);

        $this->withToken($plain)->postJson('/api/v1/partner/chat/messages', $payload)
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid API key.');

        $key->update(['is_active' => true, 'revoked_at' => null, 'expires_at' => now()->subMinute()]);

        $this->withToken($plain)->postJson('/api/v1/partner/chat/messages', $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'API key has expired.');

        $this->assertSame(0, ChatMessage::query()->count());
        $this->assertSame(0, ChatExternalMessage::query()->count());
        Bus::assertNotDispatched(ProcessChatMessageNotificationsJob::class);
    }

    public function test_product_key_cannot_send_chat_and_chat_key_cannot_read_products(): void
    {
        [$providerUser] = $this->providerWithChatKey();
        $productKey = $this->apiKeyFor($providerUser, null);

        $this->withToken($productKey)->postJson('/api/v1/partner/chat/messages', $this->payload(1))
            ->assertForbidden()
            ->assertJsonPath('message', 'API key is not authorized for this resource.');

        ProviderApiKey::query()->update(['is_active' => false, 'revoked_at' => now()]);
        $chatKey = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        $this->withToken($chatKey)->getJson('/api/v1/partner/products')
            ->assertForbidden()
            ->assertJsonPath('message', 'API key is not authorized for this resource.');

        $this->assertSame(0, ChatMessage::query()->count());
    }

    public function test_legacy_product_key_is_not_blocked_by_the_product_scope_check(): void
    {
        [$providerUser] = $this->providerWithChatKey();
        $legacyKey = $this->apiKeyFor($providerUser, null);

        $response = $this->withToken($legacyKey)->getJson('/api/v1/partner/products');

        $this->assertNotSame(403, $response->status());
        $this->assertNotSame('API key is not authorized for this resource.', $response->json('message'));
    }

    public function test_open_api_disabled_company_is_forbidden(): void
    {
        [$providerUser, $providerCompany] = $this->providerWithChatKey();
        $providerCompany->is_open_api_enabled = false;
        $providerCompany->save();
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $this->payload(1))
            ->assertForbidden()
            ->assertJsonPath('message', 'Open API access is disabled for this company.');

        $this->assertSame(0, ChatMessage::query()->count());
    }

    public function test_company_cannot_post_into_another_companys_conversation(): void
    {
        [$providerUser] = $this->providerWithChatKey();
        [$outsiderA] = $this->createCompanyUsers('Outsider A', 'user', ['Ada Out']);
        [$outsiderB] = $this->createCompanyUsers('Outsider B', 'provider', ['Bea Out']);
        $foreign = $this->conversationBetween($outsiderA, $outsiderB);
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $this->payload($foreign->id))
            ->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized.');

        $this->assertSame(0, ChatMessage::query()->count());
        $this->assertSame(0, ChatExternalConversation::query()->count());
        $this->assertSame(0, ChatExternalMessage::query()->count());
    }

    public function test_missing_mapping_and_unknown_conversation_are_not_found(): void
    {
        [$providerUser] = $this->providerWithChatKey();
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', [
            'external_system' => 'flex',
            'external_message_id' => 'msg-1',
            'external_conversation_id' => 'not-mapped',
            'body' => 'Hello',
        ])->assertNotFound()
            ->assertJsonPath('message', 'Conversation mapping not found.');

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $this->payload(999999))
            ->assertNotFound()
            ->assertJsonPath('message', 'Conversation not found.');

        $this->assertSame(0, ChatMessage::query()->count());
        $this->assertSame(0, ChatExternalConversation::query()->count());
    }

    public function test_missing_or_foreign_default_contact_is_rejected(): void
    {
        [$renter] = $this->createCompanyUsers('Renter Co', 'user', ['Rita Renter']);
        [$providerUser, $providerCompany] = $this->providerWithChatKey();
        $conversation = $this->conversationBetween($renter, $providerUser);
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        $providerCompany->default_contact_id = null;
        $providerCompany->save();

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $this->payload($conversation->id))
            ->assertStatus(422)
            ->assertJsonPath('errors.sender.0', 'This company does not have a valid default contact to send messages.');

        $providerCompany->default_contact_id = $renter->id;
        $providerCompany->save();

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $this->payload($conversation->id))
            ->assertStatus(422)
            ->assertJsonPath('errors.sender.0', 'This company does not have a valid default contact to send messages.');

        $providerCompany->default_contact_id = $providerUser->id;
        $providerCompany->save();
        $providerUser->is_blocked = true;
        $providerUser->save();

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $this->payload($conversation->id))
            ->assertStatus(422);

        $this->assertSame(0, ChatMessage::query()->count());
        $this->assertSame(0, ChatExternalMessage::query()->count());
        $this->assertSame(0, ChatExternalConversation::query()->count());
    }

    public function test_invalid_and_oversized_body_is_rejected(): void
    {
        [$providerUser] = $this->providerWithChatKey();
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', [
            'external_system' => 'rentman',
            'external_message_id' => 'msg-1',
            'external_conversation_id' => 'conv-1',
            'psm_conversation_id' => 1,
            'body' => '',
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', [
            'external_system' => 'rentman',
            'external_message_id' => 'msg-1',
            'external_conversation_id' => 'conv-1',
            'psm_conversation_id' => 1,
            'body' => str_repeat('a', 2001),
        ])->assertStatus(422);

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', [
            'external_system' => 'unknown',
            'external_message_id' => 'msg-1',
            'external_conversation_id' => 'conv-1',
            'body' => 'Hello',
        ])->assertStatus(422);

        $this->assertSame(0, ChatMessage::query()->count());
        $this->assertSame(0, ChatExternalMessage::query()->count());
    }

    public function test_duplicate_submission_returns_the_original_message_without_another_broadcast(): void
    {
        Event::fake([ChatMessageSent::class]);

        [$renter] = $this->createCompanyUsers('Renter Co', 'user', ['Rita Renter']);
        [$providerUser] = $this->providerWithChatKey();
        $conversation = $this->conversationBetween($renter, $providerUser);
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);
        $payload = $this->payload($conversation->id);

        $created = $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $payload);
        $created->assertCreated();

        $retry = $this->withToken($key)->postJson('/api/v1/partner/chat/messages', [
            'external_system' => 'rentman',
            'external_message_id' => 'external-message-123',
            'external_conversation_id' => 'external-conversation-456',
            'body' => 'Hello, we have received your rental request.',
        ]);

        $retry->assertOk()
            ->assertJsonPath('message', 'Existing message returned.')
            ->assertJsonPath('data.id', $created->json('data.id'));

        $this->assertSame(1, ChatMessage::query()->count());
        $this->assertSame(1, ChatExternalMessage::query()->count());
        Event::assertDispatchedTimes(ChatMessageSent::class, 1);
        Bus::assertDispatchedTimes(ProcessChatMessageNotificationsJob::class, 1);
    }

    public function test_reused_external_message_id_with_a_different_body_or_conversation_conflicts(): void
    {
        Event::fake([ChatMessageSent::class]);

        [$renter] = $this->createCompanyUsers('Renter Co', 'user', ['Rita Renter']);
        [$providerUser, $providerCompany] = $this->providerWithChatKey();
        [$otherRenter] = $this->createCompanyUsers('Other Renter', 'user', ['Omar Other']);
        $conversation = $this->conversationBetween($renter, $providerUser);
        $otherConversation = $this->conversationBetween($otherRenter, $providerUser);
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $this->payload($conversation->id))
            ->assertCreated();

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', [
            'external_system' => 'rentman',
            'external_message_id' => 'external-message-123',
            'external_conversation_id' => 'external-conversation-456',
            'body' => 'A different body',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'External message id has already been used for a different message.');

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', [
            'external_system' => 'rentman',
            'external_message_id' => 'msg-new',
            'external_conversation_id' => 'external-conversation-456',
            'psm_conversation_id' => $otherConversation->id,
            'body' => 'Hello from the other thread',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'External conversation id is already mapped to a different conversation.');

        $this->assertSame(1, ChatMessage::query()->count());
        $this->assertSame('Hello, we have received your rental request.', ChatMessage::query()->value('message'));
        $this->assertSame(
            (int) $conversation->id,
            (int) ChatExternalConversation::query()->where('company_id', $providerCompany->id)->value('conversation_id')
        );
        Event::assertDispatchedTimes(ChatMessageSent::class, 1);
        Bus::assertDispatchedTimes(ProcessChatMessageNotificationsJob::class, 1);
    }

    /**
     * The unique index is the persistence-level idempotency guarantee.
     * The same external message id may be stored for another company or another external system.
     * Two simultaneous HTTP requests cannot be interleaved reliably inside this single PHP process,
     * so that race is covered by the database constraint rather than by parallel requests.
     */
    public function test_duplicate_external_message_rows_are_rejected_by_the_database(): void
    {
        [$renter, $renterCompany] = $this->createCompanyUsers('Renter Co', 'user', ['Rita Renter']);
        [$providerUser, $providerCompany] = $this->providerWithChatKey();
        $conversation = $this->conversationBetween($renter, $providerUser);

        $identity = [
            'external_system' => 'rentman',
            'external_message_id' => 'same-id',
            'external_conversation_id' => 'conv',
            'conversation_id' => $conversation->id,
            'body_hash' => hash('sha256', 'Hello'),
        ];

        ChatExternalMessage::query()->create(array_merge($identity, ['company_id' => $providerCompany->id]));
        ChatExternalMessage::query()->create(array_merge($identity, ['company_id' => $renterCompany->id]));
        ChatExternalMessage::query()->create(array_merge($identity, [
            'company_id' => $providerCompany->id,
            'external_system' => 'flex',
        ]));

        $this->assertSame(3, ChatExternalMessage::query()->count());
        $this->expectException(UniqueConstraintViolationException::class);

        ChatExternalMessage::query()->create(array_merge($identity, ['company_id' => $providerCompany->id]));
    }

    public function test_mapped_conversation_can_receive_a_later_message_without_psm_id(): void
    {
        [$renter] = $this->createCompanyUsers('Renter Co', 'user', ['Rita Renter']);
        [$providerUser] = $this->providerWithChatKey();
        $conversation = $this->conversationBetween($renter, $providerUser);
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $this->payload($conversation->id))
            ->assertCreated();

        $this->withHeaders(['X-API-KEY' => $key])->postJson('/api/v1/partner/chat/messages', [
            'external_system' => 'Rentman',
            'external_message_id' => 'external-message-124',
            'external_conversation_id' => 'external-conversation-456',
            'body' => 'Second message',
        ])->assertCreated()
            ->assertJsonPath('data.conversation_id', $conversation->id);

        $this->assertSame(2, ChatMessage::query()->count());
        $this->assertSame(1, ChatExternalConversation::query()->count());
    }

    public function test_conversation_list_is_limited_to_the_authenticated_company(): void
    {
        [$renter, $renterCompany] = $this->createCompanyUsers('Renter Co', 'user', ['Rita Renter']);
        [$providerUser] = $this->providerWithChatKey();
        [$outsiderA] = $this->createCompanyUsers('Outsider A', 'user', ['Ada Out']);
        [$outsiderB] = $this->createCompanyUsers('Outsider B', 'provider', ['Bea Out']);
        $mine = $this->conversationBetween($renter, $providerUser, 44);
        $this->conversationBetween($outsiderA, $outsiderB, 99);
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        $response = $this->withToken($key)->getJson('/api/v1/partner/chat/conversations');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.counterparty_company_id', $renterCompany->id)
            ->assertJsonPath('data.0.counterparty_company_name', 'Renter Co')
            ->assertJsonPath('data.0.rental_job_id', 44);

        $this->assertArrayNotHasKey('message', $response->json('data.0'));
        $this->assertArrayNotHasKey('last_message', $response->json('data.0'));
    }

    public function test_chat_rate_limit_returns_the_partner_error_envelope(): void
    {
        config(['chat.partner_rate_limit_per_minute' => 2]);
        [$providerUser] = $this->providerWithChatKey();
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', [])->assertStatus(422);
        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', [])->assertStatus(422);
        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', [])
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Too many requests.');
    }

    public function test_generate_without_scopes_stays_product_only_and_chat_scope_can_be_requested(): void
    {
        [$providerUser] = $this->providerWithChatKey();

        $product = $this->withToken($this->tokenFor($providerUser))
            ->postJson('/api/provider/api-keys/generate', ['name' => 'Products']);

        $product->assertCreated()
            ->assertJsonPath('data.scopes', [ProviderApiKey::SCOPE_PRODUCTS]);
        $this->assertNull(ProviderApiKey::query()->where('is_active', true)->value('scopes'));

        $this->withToken($product->json('data.api_key'))
            ->postJson('/api/v1/partner/chat/messages', $this->payload(1))
            ->assertForbidden();

        $chat = $this->withToken($this->tokenFor($providerUser))
            ->postJson('/api/provider/api-keys/generate', [
                'name' => 'Chat',
                'scopes' => [ProviderApiKey::SCOPE_CHAT],
            ]);

        $chat->assertCreated()
            ->assertJsonPath('data.scopes', [ProviderApiKey::SCOPE_CHAT]);
        $this->assertSame(1, ProviderApiKey::query()->where('is_active', true)->count());
        $this->assertNotNull($chat->json('data.api_key'));
        $this->assertStringStartsWith('psm_pk_', $chat->json('data.api_key'));
    }

    public function test_non_provider_account_cannot_use_the_inbound_api(): void
    {
        [$providerUser, $providerCompany] = $this->providerWithChatKey();
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        $providerCompany->account_type = 'user';
        $providerCompany->save();

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $this->payload(1))
            ->assertForbidden()
            ->assertJsonPath('message', 'API key is not linked to a valid provider company account.');

        $this->assertNoInboundSideEffects();
    }

    public function test_explicit_products_scope_cannot_send_chat(): void
    {
        [$providerUser] = $this->providerWithChatKey();
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_PRODUCTS]);

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $this->payload(1))
            ->assertForbidden()
            ->assertJsonPath('message', 'API key is not authorized for this resource.');

        $this->withToken($key)->getJson('/api/v1/partner/chat/conversations')
            ->assertForbidden()
            ->assertJsonPath('message', 'API key is not authorized for this resource.');

        $this->assertNoInboundSideEffects();
    }

    public function test_request_cannot_impersonate_another_company_or_sender(): void
    {
        Event::fake([ChatMessageSent::class]);

        [$renter, $renterCompany] = $this->createCompanyUsers('Renter Co', 'user', ['Rita Renter']);
        [$providerUser, $providerCompany] = $this->providerWithChatKey();
        $contact = $this->additionalUser($providerCompany, 'provider', 'Default Contact');
        $providerCompany->default_contact_id = $contact->id;
        $providerCompany->save();
        $conversation = $this->conversationBetween($renter, $providerUser);
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        $response = $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $this->payload($conversation->id) + [
            'company_id' => $renterCompany->id,
            'sender_company_id' => $renterCompany->id,
            'sender_user_id' => $renter->id,
            'message_type' => 'image',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.conversation_id', $conversation->id);

        $this->assertSame(
            ['conversation_id', 'created_at', 'external_conversation_id', 'external_message_id', 'external_system', 'id'],
            collect($response->json('data'))->keys()->sort()->values()->all()
        );
        $this->assertResponseDoesNotLeak($response, $key);

        $message = ChatMessage::query()->first();
        $this->assertNotNull($message);
        $this->assertSame((int) $contact->id, (int) $message->sender_user_id);
        $this->assertSame((int) $providerCompany->id, (int) $message->sender_company_id);
        $this->assertSame(ChatMessage::TYPE_TEXT, $message->message_type);
        $this->assertNotSame((int) $renter->id, (int) $message->sender_user_id);
    }

    public function test_deleted_or_missing_default_contact_is_rejected(): void
    {
        [$renter] = $this->createCompanyUsers('Renter Co', 'user', ['Rita Renter']);
        [$providerUser, $providerCompany] = $this->providerWithChatKey();
        $conversation = $this->conversationBetween($renter, $providerUser);
        $contact = $this->additionalUser($providerCompany, 'provider', 'Former Contact');
        $providerCompany->default_contact_id = $contact->id;
        $providerCompany->save();
        $contact->delete();
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $this->payload($conversation->id))
            ->assertStatus(422)
            ->assertJsonPath('errors.sender.0', 'This company does not have a valid default contact to send messages.');

        $providerCompany->default_contact_id = 999999;
        $providerCompany->save();

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $this->payload($conversation->id))
            ->assertStatus(422)
            ->assertJsonPath('errors.sender.0', 'This company does not have a valid default contact to send messages.');

        $this->assertNoInboundSideEffects();
    }

    public function test_general_and_rental_job_conversations_stay_separate(): void
    {
        [$renter] = $this->createCompanyUsers('Renter Co', 'user', ['Rita Renter']);
        [$providerUser] = $this->providerWithChatKey();
        $general = $this->conversationBetween($renter, $providerUser);
        $job = $this->conversationBetween($renter, $providerUser, 77);
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', [
            'external_system' => 'rentman',
            'external_message_id' => 'general-msg',
            'external_conversation_id' => 'general-thread',
            'psm_conversation_id' => $general->id,
            'body' => 'General thread',
        ])->assertCreated()
            ->assertJsonPath('data.conversation_id', $general->id);

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', [
            'external_system' => 'flex',
            'external_message_id' => 'job-msg',
            'external_conversation_id' => 'job-thread',
            'psm_conversation_id' => $job->id,
            'body' => 'Job thread',
        ])->assertCreated()
            ->assertJsonPath('data.conversation_id', $job->id);

        $this->assertSame('General thread', ChatMessage::query()->where('conversation_id', $general->id)->value('message'));
        $this->assertSame('Job thread', ChatMessage::query()->where('conversation_id', $job->id)->value('message'));
        $this->assertSame(2, ChatExternalConversation::query()->count());
        $this->assertNull($general->fresh()->rental_job_id);
        $this->assertSame(77, (int) $job->fresh()->rental_job_id);
    }

    public function test_mapping_owned_by_another_company_or_system_is_not_reused(): void
    {
        [$renter] = $this->createCompanyUsers('Renter Co', 'user', ['Rita Renter']);
        [$providerUser, $providerCompany] = $this->providerWithChatKey();
        [$otherProvider, $otherCompany] = $this->providerWithChatKey();
        $mine = $this->conversationBetween($renter, $providerUser);
        $theirs = $this->conversationBetween($renter, $otherProvider);
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        ChatExternalConversation::query()->create([
            'company_id' => $otherCompany->id,
            'external_system' => 'rentman',
            'external_conversation_id' => 'shared-thread',
            'conversation_id' => $theirs->id,
        ]);
        ChatExternalConversation::query()->create([
            'company_id' => $providerCompany->id,
            'external_system' => 'flex',
            'external_conversation_id' => 'shared-thread',
            'conversation_id' => $mine->id,
        ]);

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', [
            'external_system' => 'rentman',
            'external_message_id' => 'msg-unmapped',
            'external_conversation_id' => 'shared-thread',
            'body' => 'Should not attach to the other company',
        ])->assertNotFound()
            ->assertJsonPath('message', 'Conversation mapping not found.');

        $this->assertSame(0, ChatMessage::query()->count());
        $this->assertSame(0, ChatExternalMessage::query()->count());

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', [
            'external_system' => 'flex',
            'external_message_id' => 'msg-flex',
            'external_conversation_id' => 'shared-thread',
            'body' => 'Uses our flex mapping',
        ])->assertCreated()
            ->assertJsonPath('data.conversation_id', $mine->id);

        $this->assertSame(
            (int) $theirs->id,
            (int) ChatExternalConversation::query()->where('company_id', $otherCompany->id)->value('conversation_id')
        );
        $this->assertSame(1, ChatMessage::query()->count());
    }

    public function test_mapping_that_points_outside_the_company_is_forbidden(): void
    {
        [$providerUser, $providerCompany] = $this->providerWithChatKey();
        [$outsiderA] = $this->createCompanyUsers('Outsider A', 'user', ['Ada Out']);
        [$outsiderB] = $this->createCompanyUsers('Outsider B', 'provider', ['Bea Out']);
        $foreign = $this->conversationBetween($outsiderA, $outsiderB);
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        ChatExternalConversation::query()->create([
            'company_id' => $providerCompany->id,
            'external_system' => 'rentman',
            'external_conversation_id' => 'foreign-link',
            'conversation_id' => $foreign->id,
        ]);

        $response = $this->withToken($key)->postJson('/api/v1/partner/chat/messages', [
            'external_system' => 'rentman',
            'external_message_id' => 'msg-foreign',
            'external_conversation_id' => 'foreign-link',
            'body' => 'Should not enter the other thread',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized.');
        $this->assertResponseDoesNotLeak($response, $key);
        $this->assertStringNotContainsString('Should not enter the other thread', $response->getContent());
        $this->assertSame(0, ChatMessage::query()->count());
        $this->assertSame(0, ChatExternalMessage::query()->count());
        Bus::assertNotDispatched(ProcessChatMessageNotificationsJob::class);
    }

    public function test_same_external_message_id_is_distinct_across_companies_and_systems(): void
    {
        [$renter] = $this->createCompanyUsers('Renter Co', 'user', ['Rita Renter']);
        [$providerUser] = $this->providerWithChatKey();
        [$otherProvider] = $this->providerWithChatKey();
        $mine = $this->conversationBetween($renter, $providerUser);
        $theirs = $this->conversationBetween($renter, $otherProvider);
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);
        $otherKey = $this->apiKeyFor($otherProvider, [ProviderApiKey::SCOPE_CHAT]);

        $shared = [
            'external_message_id' => 'shared-message',
            'external_conversation_id' => 'shared-thread',
            'body' => 'Same external id, different scope',
        ];

        $first = $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $shared + [
            'external_system' => 'rentman',
            'psm_conversation_id' => $mine->id,
        ]);
        $second = $this->withToken($otherKey)->postJson('/api/v1/partner/chat/messages', $shared + [
            'external_system' => 'rentman',
            'psm_conversation_id' => $theirs->id,
        ]);
        $third = $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $shared + [
            'external_system' => 'flex',
            'psm_conversation_id' => $mine->id,
            'external_conversation_id' => 'flex-thread',
        ]);

        $first->assertCreated();
        $second->assertCreated();
        $third->assertCreated();
        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertNotSame($first->json('data.id'), $third->json('data.id'));
        $this->assertSame(3, ChatMessage::query()->count());
        $this->assertSame(3, ChatExternalMessage::query()->count());

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $shared + [
            'external_system' => '  Rentman  ',
            'external_message_id' => '  shared-message  ',
            'external_conversation_id' => '  shared-thread  ',
        ])->assertOk()
            ->assertJsonPath('message', 'Existing message returned.')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(3, ChatMessage::query()->count());
    }

    public function test_duplicate_external_conversation_rows_are_rejected_by_the_database(): void
    {
        [$renter] = $this->createCompanyUsers('Renter Co', 'user', ['Rita Renter']);
        [$providerUser, $providerCompany] = $this->providerWithChatKey();
        $conversation = $this->conversationBetween($renter, $providerUser);

        ChatExternalConversation::query()->create([
            'company_id' => $providerCompany->id,
            'external_system' => 'rentman',
            'external_conversation_id' => 'same-thread',
            'conversation_id' => $conversation->id,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        ChatExternalConversation::query()->create([
            'company_id' => $providerCompany->id,
            'external_system' => 'rentman',
            'external_conversation_id' => 'same-thread',
            'conversation_id' => $conversation->id,
        ]);
    }

    public function test_invalid_payloads_are_rejected_without_partial_records(): void
    {
        [$providerUser] = $this->providerWithChatKey();
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);
        $base = [
            'external_system' => 'rentman',
            'external_message_id' => 'msg-1',
            'external_conversation_id' => 'conv-1',
            'psm_conversation_id' => 1,
            'body' => 'Hello',
        ];

        $cases = [
            'missing fields' => [[], 'external_system'],
            'whitespace body' => [array_merge($base, ['body' => "  \n\t"]), 'body'],
            'body too long' => [array_merge($base, ['body' => str_repeat('a', 2001)]), 'body'],
            'body not a string' => [array_merge($base, ['body' => ['nested']]), 'body'],
            'blank external message id' => [array_merge($base, ['external_message_id' => '   ']), 'external_message_id'],
            'external message id too long' => [array_merge($base, ['external_message_id' => str_repeat('m', 192)]), 'external_message_id'],
            'numeric external message id' => [array_merge($base, ['external_message_id' => 12345]), 'external_message_id'],
            'blank external conversation id' => [array_merge($base, ['external_conversation_id' => ' ']), 'external_conversation_id'],
            'unknown external system' => [array_merge($base, ['external_system' => 'other-software']), 'external_system'],
            'psm conversation id text' => [array_merge($base, ['psm_conversation_id' => 'abc']), 'psm_conversation_id'],
            'psm conversation id zero' => [array_merge($base, ['psm_conversation_id' => 0]), 'psm_conversation_id'],
            'psm conversation id negative' => [array_merge($base, ['psm_conversation_id' => -5]), 'psm_conversation_id'],
            'invalid timestamp' => [array_merge($base, ['external_created_at' => 'not-a-date']), 'external_created_at'],
        ];

        foreach ($cases as $name => [$payload, $errorKey]) {
            $response = $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $payload);

            $this->assertSame(422, $response->status(), $name);
            $this->assertFalse($response->json('success'), $name);
            $this->assertSame('Validation error', $response->json('message'), $name);
            $this->assertNotEmpty($response->json('errors.'.$errorKey), $name);
            $this->assertResponseDoesNotLeak($response, $key);
        }

        $this->assertNoInboundSideEffects();
    }

    public function test_malformed_json_does_not_create_records_or_leak_internals(): void
    {
        [$providerUser] = $this->providerWithChatKey();
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        $response = $this->call(
            'POST',
            '/api/v1/partner/chat/messages',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$key,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            '{not-json'
        );

        $this->assertContains($response->status(), [400, 422]);
        $this->assertResponseDoesNotLeak($response, $key);
        $this->assertNoInboundSideEffects();
    }

    public function test_new_message_touches_activity_once_and_a_retry_does_not(): void
    {
        Event::fake([ChatMessageSent::class]);
        Carbon::setTestNow('2026-10-12 08:00:00');

        [$renter] = $this->createCompanyUsers('Renter Co', 'user', ['Rita Renter']);
        [$providerUser] = $this->providerWithChatKey();
        $conversation = $this->conversationBetween($renter, $providerUser);
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        Carbon::setTestNow('2026-10-12 09:30:00');
        $created = $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $this->payload($conversation->id));
        $created->assertCreated();

        $conversation->refresh();
        $this->assertSame('2026-10-12 09:30:00', $conversation->updated_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-12 09:30:00', ChatMessage::query()->first()->created_at?->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $this->payload($conversation->id))
            ->assertOk()
            ->assertJsonPath('data.id', $created->json('data.id'));

        $this->assertSame('2026-10-12 09:30:00', $conversation->fresh()->updated_at?->format('Y-m-d H:i:s'));
        $this->assertSame(1, ChatMessage::query()->count());
        Event::assertDispatchedTimes(ChatMessageSent::class, 1);
        Bus::assertDispatchedTimes(ProcessChatMessageNotificationsJob::class, 1);
    }

    public function test_retry_of_a_deleted_message_does_not_create_a_replacement(): void
    {
        [$renter] = $this->createCompanyUsers('Renter Co', 'user', ['Rita Renter']);
        [$providerUser] = $this->providerWithChatKey();
        $conversation = $this->conversationBetween($renter, $providerUser);
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);
        $payload = $this->payload($conversation->id);

        $created = $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $payload);
        $created->assertCreated();
        $messageId = (int) $created->json('data.id');

        ChatMessage::query()->find($messageId)?->delete();

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Existing message returned.')
            ->assertJsonPath('data.id', $messageId);

        $this->assertSame(0, ChatMessage::query()->count());
        $this->assertSame(1, ChatMessage::withTrashed()->count());
        $this->assertSame(1, ChatExternalMessage::query()->count());
    }

    public function test_inbound_message_stays_inside_existing_chat_and_skips_the_chatbot(): void
    {
        Event::fake([ChatMessageSent::class]);
        Http::fake();

        [$renter, $renterCompany] = $this->createCompanyUsers('Renter Co', 'user', ['Rita Renter']);
        [$providerUser, $providerCompany] = $this->providerWithChatKey();
        [$outsider] = $this->createCompanyUsers('Outsider Co', 'user', ['Owen Out']);
        $conversation = $this->conversationBetween($renter, $providerUser, 18);
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        $created = $this->withToken($key)->postJson('/api/v1/partner/chat/messages', [
            'external_system' => 'flex',
            'external_message_id' => 'flex-msg-1',
            'external_conversation_id' => 'flex-conv-1',
            'psm_conversation_id' => $conversation->id,
            'body' => 'The speakers are confirmed.',
        ]);

        $created->assertCreated();
        $messageId = (int) $created->json('data.id');
        $this->assertResponseDoesNotLeak($created, $key);

        $history = $this->withToken($this->tokenFor($renter))
            ->getJson("/api/chat/conversations/{$conversation->id}/messages");

        $history->assertOk()
            ->assertJsonPath('data.0.id', $messageId)
            ->assertJsonPath('data.0.message', 'The speakers are confirmed.')
            ->assertJsonPath('data.0.message_type', ChatMessage::TYPE_TEXT)
            ->assertJsonPath('data.0.sender_user_id', $providerUser->id)
            ->assertJsonPath('data.0.sender_company_id', $providerCompany->id)
            ->assertJsonPath('data.0.is_mine', false);

        $this->withToken($this->tokenFor($providerUser))
            ->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.is_mine', true)
            ->assertJsonPath('data.0.sender_company_id', $providerCompany->id);

        $this->withToken($this->tokenFor($outsider))
            ->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized.');

        $this->withToken($this->tokenFor($outsider))
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'message' => 'I should not be able to reply',
            ])
            ->assertForbidden();

        $this->withToken($this->tokenFor($renter))
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", [
                'message' => 'Thanks, we will collect them tomorrow.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sender_company_id', $renterCompany->id)
            ->assertJsonPath('data.is_mine', true);

        $this->assertSame(2, ChatMessage::query()->count());
        $this->assertSame(
            ChatMessage::TYPE_TEXT,
            ChatMessage::query()->whereKey($messageId)->value('message_type')
        );

        $this->withToken($this->tokenFor($providerUser))
            ->getJson('/api/chat/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 1);

        $this->withToken($this->tokenFor($renter))
            ->getJson('/api/chat/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 1);

        Event::assertDispatched(ChatMessageSent::class, function (ChatMessageSent $event) use ($conversation, $providerUser, $providerCompany, $renterCompany) {
            $channelNames = array_map(fn ($channel) => $channel->name, $event->broadcastOn());
            $payload = $event->broadcastWith();

            return $event->broadcastAs() === 'chat.message.sent'
                && (int) $event->message->id === (int) ChatMessage::query()->where('sender_company_id', $providerCompany->id)->value('id')
                && in_array('presence-'.ChatChannels::conversation((int) $conversation->id), $channelNames, true)
                && in_array('private-'.ChatChannels::company((int) $renterCompany->id), $channelNames, true)
                && in_array('private-'.ChatChannels::company((int) $providerCompany->id), $channelNames, true)
                && $payload['message'] === 'The speakers are confirmed.'
                && $payload['message_type'] === ChatMessage::TYPE_TEXT
                && (int) $payload['sender_user_id'] === (int) $providerUser->id
                && (int) $payload['sender_company_id'] === (int) $providerCompany->id
                && (int) $payload['conversation_id'] === (int) $conversation->id
                && (int) $payload['rental_job_id'] === 18
                && ! array_key_exists('api_key', $payload);
        });
        Event::assertDispatchedTimes(ChatMessageSent::class, 2);
        Http::assertNothingSent();
    }

    public function test_conversation_discovery_is_paginated_and_rejects_unauthorized_callers(): void
    {
        [$renter, $renterCompany] = $this->createCompanyUsers('Renter Co', 'user', ['Rita Renter']);
        [$providerUser, $providerCompany] = $this->providerWithChatKey();
        [$outsiderA] = $this->createCompanyUsers('Outsider A', 'user', ['Ada Out']);
        [$outsiderB] = $this->createCompanyUsers('Outsider B', 'provider', ['Bea Out']);
        $general = $this->conversationBetween($renter, $providerUser);
        $job = $this->conversationBetween($renter, $providerUser, 15);
        $foreign = $this->conversationBetween($outsiderA, $outsiderB, 99);
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);
        $productKey = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_PRODUCTS]);

        ChatMessage::query()->create([
            'conversation_id' => $general->id,
            'sender_user_id' => $providerUser->id,
            'sender_company_id' => $providerCompany->id,
            'message' => 'Private history that must stay hidden',
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);
        ChatConversation::query()->whereKey($job->id)->update(['updated_at' => '2026-10-12 12:00:00']);
        ChatConversation::query()->whereKey($general->id)->update(['updated_at' => '2026-10-11 12:00:00']);

        $page = $this->withToken($key)->getJson('/api/v1/partner/chat/conversations?per_page=1&page=1');
        $page->assertOk()
            ->assertJsonPath('message', 'Conversations fetched successfully.')
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.total_pages', 2)
            ->assertJsonPath('data.0.id', $job->id)
            ->assertJsonPath('data.0.counterparty_company_id', $renterCompany->id)
            ->assertJsonPath('data.0.counterparty_company_name', 'Renter Co')
            ->assertJsonPath('data.0.rental_job_id', 15);
        $this->assertSame(
            ['counterparty_company_id', 'counterparty_company_name', 'id', 'rental_job_id', 'updated_at'],
            collect($page->json('data.0'))->keys()->sort()->values()->all()
        );
        $this->assertStringNotContainsString('Private history that must stay hidden', $page->getContent());
        $this->assertResponseDoesNotLeak($page, $key);

        $next = $this->withToken($key)->getJson('/api/v1/partner/chat/conversations?per_page=1&page=2');
        $next->assertOk()
            ->assertJsonPath('data.0.id', $general->id)
            ->assertJsonPath('data.0.rental_job_id', null);

        $empty = $this->withToken($key)->getJson('/api/v1/partner/chat/conversations?per_page=1&page=3');
        $empty->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(0, 'data');

        $listedIds = [$page->json('data.0.id'), $next->json('data.0.id')];
        $this->assertNotContains($foreign->id, $listedIds);

        $this->flushHeaders()
            ->getJson('/api/v1/partner/chat/conversations')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'API key is required.');

        $this->withToken('psm_pk_not-a-real-key')->getJson('/api/v1/partner/chat/conversations')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid API key.');

        $this->withToken($productKey)->getJson('/api/v1/partner/chat/conversations')
            ->assertForbidden()
            ->assertJsonPath('message', 'API key is not authorized for this resource.');

        $this->withToken($key)->getJson('/api/v1/partner/chat/conversations?per_page=101')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Validation error');

        $pageError = $this->withToken($key)->getJson('/api/v1/partner/chat/conversations?page=0');
        $pageError->assertStatus(422)
            ->assertJsonPath('message', 'Validation error');
        $this->assertNotEmpty($pageError->json('errors.page'));
    }

    /**
     * The partner-chat limiter is written to bucket by authenticated company.
     * A second company must not inherit another company's count, and a new
     * external message id must not start a new bucket.
     */
    public function test_rate_limit_is_per_company_and_ignores_changed_payload_fields(): void
    {
        config(['chat.partner_rate_limit_per_minute' => 1]);

        [$firstUser] = $this->providerWithChatKey();
        [$secondUser] = $this->providerWithChatKey();
        $firstKey = $this->apiKeyFor($firstUser, [ProviderApiKey::SCOPE_CHAT]);
        $sameCompanyKey = $this->apiKeyFor($firstUser, [ProviderApiKey::SCOPE_CHAT]);
        $secondKey = $this->apiKeyFor($secondUser, [ProviderApiKey::SCOPE_CHAT]);

        $this->withToken($firstKey)->postJson('/api/v1/partner/chat/messages', [])
            ->assertStatus(422);

        $limited = $this->withToken($sameCompanyKey)->postJson('/api/v1/partner/chat/messages', [
            'external_system' => 'flex',
            'external_message_id' => 'a-different-id',
            'external_conversation_id' => 'a-different-thread',
            'body' => 'This must not reset the company bucket',
        ]);
        $limited->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Too many requests.');
        $this->assertResponseDoesNotLeak($limited, $sameCompanyKey);

        $this->withToken($secondKey)->postJson('/api/v1/partner/chat/messages', [])
            ->assertStatus(422);

        $this->assertNoInboundSideEffects();
    }

    public function test_missing_api_key_is_rate_limited_separately_from_a_company(): void
    {
        config(['chat.partner_rate_limit_per_minute' => 1]);

        [$providerUser] = $this->providerWithChatKey();
        $key = $this->apiKeyFor($providerUser, [ProviderApiKey::SCOPE_CHAT]);

        $this->flushHeaders()
            ->postJson('/api/v1/partner/chat/messages', [])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'API key is required.');

        $this->flushHeaders()
            ->postJson('/api/v1/partner/chat/messages', [])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many requests.');

        $this->withToken($key)->postJson('/api/v1/partner/chat/messages', $this->payload(1))
            ->assertNotFound();

        $this->assertNoInboundSideEffects();
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function providerWithChatKey(): array
    {
        [$user, $company] = $this->createCompanyUsers('Provider Co '.uniqid(), 'provider', ['Pat Provider']);
        $company->is_open_api_enabled = true;
        $company->save();

        return [$user->fresh(), $company->fresh()];
    }

    /**
     * @param  list<string>|null  $scopes
     */
    private function apiKeyFor(User $user, ?array $scopes): string
    {
        $plain = 'psm_pk_'.Str::random(48);

        ProviderApiKey::query()->create([
            'provider_user_id' => $user->id,
            'name' => 'Test key',
            'key_prefix' => substr($plain, 0, 14),
            'key_hash' => hash('sha256', $plain),
            'encrypted_key' => Crypt::encryptString($plain),
            'is_active' => true,
            'scopes' => $scopes,
        ]);

        return $plain;
    }

    private function conversationBetween(User $left, User $right, ?int $rentalJobId = null): ChatConversation
    {
        $leftCompanyId = (int) $left->company_id;
        $rightCompanyId = (int) $right->company_id;

        return ChatConversation::query()->create([
            'company_a_id' => min($leftCompanyId, $rightCompanyId),
            'company_b_id' => max($leftCompanyId, $rightCompanyId),
            'created_by_user_id' => $left->id,
            'rental_job_id' => $rentalJobId,
            'pair_key' => ChatConversation::pairKeyFor($leftCompanyId, $rightCompanyId, $rentalJobId),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $conversationId): array
    {
        return [
            'external_system' => 'rentman',
            'external_message_id' => 'external-message-123',
            'external_conversation_id' => 'external-conversation-456',
            'psm_conversation_id' => $conversationId,
            'body' => 'Hello, we have received your rental request.',
            'external_created_at' => '2026-10-12T10:30:00Z',
        ];
    }

    private function additionalUser(Company $company, string $accountType, string $fullName): User
    {
        $user = User::query()->create([
            'account_type' => $accountType,
            'username' => 'user_'.uniqid(),
            'email' => uniqid('u_', true).'@example.com',
            'password' => Hash::make('password'),
            'company_id' => $company->id,
            'is_admin' => 0,
            'is_company_default_contact' => false,
            'role' => 'user',
            'email_verified' => true,
        ]);

        $parts = explode(' ', $fullName, 2);
        UserProfile::query()->create([
            'user_id' => $user->id,
            'full_name' => $fullName,
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? '',
        ]);

        return $user->fresh(['profile', 'company']);
    }

    private function assertNoInboundSideEffects(): void
    {
        $this->assertSame(0, ChatMessage::query()->count());
        $this->assertSame(0, ChatExternalMessage::query()->count());
        $this->assertSame(0, ChatExternalConversation::query()->count());
        Bus::assertNotDispatched(ProcessChatMessageNotificationsJob::class);
    }

    private function assertResponseDoesNotLeak(TestResponse $response, string $plainKey): void
    {
        $content = $response->getContent();

        $this->assertStringNotContainsString($plainKey, $content);
        $this->assertStringNotContainsString('encrypted_key', $content);
        $this->assertStringNotContainsString('key_hash', $content);
        $this->assertStringNotContainsString('SQLSTATE', $content);
        $this->assertStringNotContainsString('vendor/laravel', $content);
        $this->assertStringNotContainsString('Stack trace', $content);
    }

    private function createPartnerChatSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('chat_external_messages');
        Schema::dropIfExists('chat_external_conversations');
        Schema::dropIfExists('provider_api_keys');
        Schema::enableForeignKeyConstraints();

        $this->createMinimalChatSchema();

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('is_open_api_enabled')->default(false);
        });

        Schema::create('provider_api_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('provider_user_id');
            $table->string('name', 100)->nullable();
            $table->json('scopes')->nullable();
            $table->string('key_prefix', 20);
            $table->string('key_hash', 64)->unique();
            $table->text('encrypted_key')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_external_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('external_system', 32);
            $table->string('external_conversation_id', 191);
            $table->unsignedBigInteger('conversation_id');
            $table->timestamps();
            $table->unique(
                ['company_id', 'external_system', 'external_conversation_id'],
                'chat_ext_conversations_identity_unique'
            );
        });

        Schema::create('chat_external_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('external_system', 32);
            $table->string('external_message_id', 191);
            $table->string('external_conversation_id', 191);
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('body_hash', 64);
            $table->timestamp('external_created_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['company_id', 'external_system', 'external_message_id'],
                'chat_ext_messages_identity_unique'
            );
        });
    }
}
