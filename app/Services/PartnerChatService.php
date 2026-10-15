<?php

namespace App\Services;

use App\Exceptions\PartnerChatException;
use App\Models\ChatConversation;
use App\Models\ChatExternalConversation;
use App\Models\ChatExternalMessage;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\User;
use App\Support\ChatLog;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class PartnerChatService
{
    public function __construct(
        private readonly ChatService $chatService,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{status: int, body: array<string, mixed>}
     */
    public function ingest(Company $company, array $input): array
    {
        $sender = $this->senderFor($company);
        if (! $sender) {
            return PartnerChatException::senderMissing()->toResult();
        }

        try {
            return $this->ingestWithinTransaction($company, $sender, $input, 1);
        } catch (PartnerChatException $e) {
            return $e->toResult();
        } catch (Throwable $e) {
            ChatLog::error('Partner chat ingest failed', [
                'company_id' => $company->id,
                'external_system' => $input['external_system'] ?? null,
                'external_message_id' => $input['external_message_id'] ?? null,
                'exception_class' => $e::class,
            ]);

            return [
                'status' => 500,
                'body' => [
                    'success' => false,
                    'message' => 'Unable to accept the message.',
                ],
            ];
        }
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function listConversations(Company $company, int $perPage): array
    {
        $paginator = ChatConversation::query()
            ->where(function ($query) use ($company) {
                $query->where('company_a_id', $company->id)
                    ->orWhere('company_b_id', $company->id);
            })
            ->with(['companyA:id,name', 'companyB:id,name'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $data = $paginator->getCollection()
            ->map(function (ChatConversation $conversation) use ($company) {
                $counterparty = $conversation->otherCompany((int) $company->id);

                return [
                    'id' => $conversation->id,
                    'counterparty_company_id' => $counterparty?->id,
                    'counterparty_company_name' => $counterparty?->name,
                    'rental_job_id' => $conversation->rental_job_id,
                    'updated_at' => $conversation->updated_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return [
            'data' => $data,
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{status: int, body: array<string, mixed>}
     */
    private function ingestWithinTransaction(Company $company, User $sender, array $input, int $attempt): array
    {
        try {
            return DB::transaction(function () use ($company, $sender, $input) {
                return $this->ingestLocked($company, $sender, $input);
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->recoverFromUniqueViolation($company, $sender, $input, $attempt, $e);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return $this->recoverFromUniqueViolation($company, $sender, $input, $attempt, $e);
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{status: int, body: array<string, mixed>}
     */
    private function recoverFromUniqueViolation(
        Company $company,
        User $sender,
        array $input,
        int $attempt,
        Throwable $exception
    ): array {
        if ($attempt >= 2) {
            $existing = $this->findExternalMessage($company, $input);
            if ($existing) {
                return $this->existingResult($company, $sender, $existing, $input);
            }

            ChatLog::error('Partner chat ingest conflict could not be resolved', [
                'company_id' => $company->id,
                'external_system' => $input['external_system'] ?? null,
                'external_message_id' => $input['external_message_id'] ?? null,
                'exception_class' => $exception::class,
            ]);

            return [
                'status' => 500,
                'body' => [
                    'success' => false,
                    'message' => 'Unable to accept the message.',
                ],
            ];
        }

        return $this->ingestWithinTransaction($company, $sender, $input, $attempt + 1);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{status: int, body: array<string, mixed>}
     */
    private function ingestLocked(Company $company, User $sender, array $input): array
    {
        $existing = $this->findExternalMessage($company, $input, lock: true);
        if ($existing) {
            return $this->existingResult($company, $sender, $existing, $input);
        }

        $conversation = $this->resolveConversation($company, $input);
        $body = (string) $input['body'];
        $externalCreatedAt = $this->externalCreatedAt($input);

        $link = ChatExternalMessage::query()->create([
            'company_id' => $company->id,
            'external_system' => $input['external_system'],
            'external_message_id' => $input['external_message_id'],
            'external_conversation_id' => $input['external_conversation_id'],
            'conversation_id' => $conversation->id,
            'message_id' => null,
            'body_hash' => $this->bodyHash($body),
            'external_created_at' => $externalCreatedAt,
        ]);

        $message = $this->chatService->sendMessage($conversation, $sender, $body);
        $link->message_id = $message->id;
        $link->save();

        ChatLog::info('Partner chat message accepted', [
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'external_system' => $input['external_system'],
            'external_message_id' => $input['external_message_id'],
        ]);

        return $this->successResult($message, $link, created: true);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function findExternalMessage(Company $company, array $input, bool $lock = false): ?ChatExternalMessage
    {
        $query = ChatExternalMessage::query()
            ->where('company_id', $company->id)
            ->where('external_system', $input['external_system'])
            ->where('external_message_id', $input['external_message_id']);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{status: int, body: array<string, mixed>}
     */
    private function existingResult(Company $company, User $sender, ChatExternalMessage $existing, array $input): array
    {
        $body = (string) $input['body'];
        $psmConversationId = isset($input['psm_conversation_id']) ? (int) $input['psm_conversation_id'] : null;

        if (! $this->sameIdentity($existing, $this->bodyHash($body), (string) $input['external_conversation_id'], $psmConversationId)) {
            throw PartnerChatException::conflict('External message id has already been used for a different message.');
        }

        if ($existing->message_id === null) {
            $conversation = ChatConversation::query()->find($existing->conversation_id);
            if (! $conversation || ! $conversation->involvesCompany((int) $company->id)) {
                throw PartnerChatException::forbidden();
            }

            $message = $this->chatService->sendMessage($conversation, $sender, $body);
            $existing->message_id = $message->id;
            $existing->save();

            return $this->successResult($message, $existing, created: true);
        }

        $message = ChatMessage::query()->withTrashed()->find($existing->message_id);
        if (! $message) {
            throw PartnerChatException::conflict('External message id has already been used for a different message.');
        }

        return $this->successResult($message, $existing, created: false);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveConversation(Company $company, array $input): ChatConversation
    {
        $system = (string) $input['external_system'];
        $externalConversationId = (string) $input['external_conversation_id'];
        $psmConversationId = isset($input['psm_conversation_id']) ? (int) $input['psm_conversation_id'] : null;

        $mapping = ChatExternalConversation::query()
            ->where('company_id', $company->id)
            ->where('external_system', $system)
            ->where('external_conversation_id', $externalConversationId)
            ->lockForUpdate()
            ->first();

        if ($mapping) {
            $conversation = ChatConversation::query()->find($mapping->conversation_id);
            if (! $conversation || ! $conversation->involvesCompany((int) $company->id)) {
                throw PartnerChatException::forbidden();
            }

            if ($psmConversationId !== null && (int) $conversation->id !== $psmConversationId) {
                throw PartnerChatException::conflict('External conversation id is already mapped to a different conversation.');
            }

            return $conversation;
        }

        if ($psmConversationId === null) {
            throw PartnerChatException::notFound('Conversation mapping not found.');
        }

        $conversation = ChatConversation::query()->find($psmConversationId);
        if (! $conversation) {
            throw PartnerChatException::notFound('Conversation not found.');
        }

        if (! $conversation->involvesCompany((int) $company->id)) {
            throw PartnerChatException::forbidden();
        }

        ChatExternalConversation::query()->create([
            'company_id' => $company->id,
            'external_system' => $system,
            'external_conversation_id' => $externalConversationId,
            'conversation_id' => $conversation->id,
        ]);

        return $conversation;
    }

    private function sameIdentity(
        ChatExternalMessage $existing,
        string $bodyHash,
        string $externalConversationId,
        ?int $psmConversationId
    ): bool {
        if (! hash_equals((string) $existing->body_hash, $bodyHash)) {
            return false;
        }

        if ($existing->external_conversation_id !== $externalConversationId) {
            return false;
        }

        if ($psmConversationId !== null && (int) $existing->conversation_id !== $psmConversationId) {
            return false;
        }

        return true;
    }

    private function senderFor(Company $company): ?User
    {
        $contactId = (int) ($company->default_contact_id ?? 0);
        if ($contactId <= 0) {
            return null;
        }

        $user = User::query()->find($contactId);
        if (! $user || (int) $user->company_id !== (int) $company->id) {
            return null;
        }

        if ((bool) $user->is_blocked) {
            return null;
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function externalCreatedAt(array $input): ?Carbon
    {
        if (empty($input['external_created_at'])) {
            return null;
        }

        return Carbon::parse($input['external_created_at']);
    }

    private function bodyHash(string $body): string
    {
        return hash('sha256', $body);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function successResult(ChatMessage $message, ChatExternalMessage $link, bool $created): array
    {
        return [
            'status' => $created ? 201 : 200,
            'body' => [
                'success' => true,
                'message' => $created ? 'Message sent.' : 'Existing message returned.',
                'data' => [
                    'id' => $message->id,
                    'conversation_id' => $message->conversation_id,
                    'external_system' => $link->external_system,
                    'external_message_id' => $link->external_message_id,
                    'external_conversation_id' => $link->external_conversation_id,
                    'created_at' => $message->created_at?->toIso8601String(),
                ],
            ],
        ];
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }
}
