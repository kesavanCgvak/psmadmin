<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Models\User;
use App\Services\InventoryAi\Exceptions\AiProviderException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ChatbotService
{
    public function __construct(
        private readonly ChatbotAiClient $aiClient,
        private readonly ChatbotKnowledgeRetriever $retriever,
    ) {}

    public function startConversation(?User $user, string $source = ChatbotConversation::SOURCE_API): ChatbotConversation
    {
        return ChatbotConversation::create([
            'user_id' => $user?->id,
            'source' => $source,
            'status' => ChatbotConversation::STATUS_OPEN,
            'title' => 'New conversation',
        ]);
    }

    /**
     * @return array{
     *     conversation: ChatbotConversation,
     *     user_message: ChatbotMessage,
     *     assistant_message: ChatbotMessage,
     *     knowledge_ids: list<int>
     * }
     */
    public function sendMessage(
        ChatbotConversation $conversation,
        string $message,
        ?User $user = null,
    ): array {
        $message = trim($message);
        if ($message === '') {
            throw new \InvalidArgumentException('Message cannot be empty.');
        }

        if ($user && $conversation->user_id && (int) $conversation->user_id !== (int) $user->id) {
            throw new \RuntimeException('Conversation does not belong to this user.');
        }

        return DB::transaction(function () use ($conversation, $message) {
            $userMessage = $conversation->messages()->create([
                'role' => ChatbotMessage::ROLE_USER,
                'content' => $message,
            ]);

            if (!$conversation->title || $conversation->title === 'New conversation') {
                $conversation->title = Str::limit($message, 80);
            }

            $knowledge = $this->retriever->findRelevant($message);
            $knowledgeIds = $knowledge->pluck('id')->map(fn ($id) => (int) $id)->all();

            $systemPrompt = $this->buildSystemPrompt($knowledge);
            $history = $this->buildHistoryMessages($conversation, $userMessage);

            try {
                $aiResult = $this->aiClient->chat($systemPrompt, $history);
                $reply = $aiResult['content'];
                $meta = [
                    'provider' => $aiResult['provider'],
                    'model' => $aiResult['model'],
                    'knowledge_ids' => $knowledgeIds,
                ];
            } catch (AiProviderException $e) {
                Log::warning('Chatbot AI provider failed', [
                    'conversation_id' => $conversation->id,
                    'category' => $e->category,
                    'provider' => $e->provider,
                    'message' => $e->getMessage(),
                ]);

                $reply = $knowledge->isNotEmpty()
                    ? $this->buildOfflineReply($knowledge)
                    : (string) config('chatbot.fallback_reply');

                $meta = [
                    'provider' => $e->provider,
                    'error_category' => $e->category,
                    'knowledge_ids' => $knowledgeIds,
                    'fallback' => true,
                ];
            } catch (Throwable $e) {
                Log::error('Chatbot unexpected failure', [
                    'conversation_id' => $conversation->id,
                    'message' => $e->getMessage(),
                ]);

                $reply = (string) config('chatbot.fallback_reply');
                $meta = [
                    'knowledge_ids' => $knowledgeIds,
                    'fallback' => true,
                    'error' => 'unexpected',
                ];
            }

            $assistantMessage = $conversation->messages()->create([
                'role' => ChatbotMessage::ROLE_ASSISTANT,
                'content' => $reply,
                'meta' => $meta,
            ]);

            $conversation->forceFill([
                'last_message_at' => now(),
            ])->save();

            return [
                'conversation' => $conversation->fresh(['messages']),
                'user_message' => $userMessage,
                'assistant_message' => $assistantMessage,
                'knowledge_ids' => $knowledgeIds,
            ];
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\ChatbotKnowledge>  $knowledge
     */
    private function buildSystemPrompt($knowledge): string
    {
        $base = trim((string) config('chatbot.system_prompt'));

        if ($knowledge->isEmpty()) {
            return $base."\n\nKnowledge base excerpts:\n(none matched — say you do not have enough information.)";
        }

        $snippets = $knowledge
            ->values()
            ->map(fn ($entry, $index) => '--- Entry '.($index + 1)." ---\n".$entry->toPromptSnippet())
            ->implode("\n\n");

        return $base."\n\nKnowledge base excerpts:\n".$snippets;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function buildHistoryMessages(ChatbotConversation $conversation, ChatbotMessage $currentUserMessage): array
    {
        $limit = max(2, (int) config('chatbot.max_history_messages', 12));

        $prior = $conversation->messages()
            ->where('id', '<', $currentUserMessage->id)
            ->whereIn('role', [ChatbotMessage::ROLE_USER, ChatbotMessage::ROLE_ASSISTANT])
            ->orderByDesc('id')
            ->limit($limit - 1)
            ->get()
            ->reverse()
            ->values();

        $messages = [];
        foreach ($prior as $row) {
            $messages[] = [
                'role' => $row->role,
                'content' => $row->content,
            ];
        }

        $messages[] = [
            'role' => ChatbotMessage::ROLE_USER,
            'content' => $currentUserMessage->content,
        ];

        return $messages;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\ChatbotKnowledge>  $knowledge
     */
    private function buildOfflineReply($knowledge): string
    {
        $top = $knowledge->first();
        if (!$top) {
            return (string) config('chatbot.fallback_reply');
        }

        $prefix = $top->question
            ? "Based on our knowledge base regarding \"{$top->question}\":\n\n"
            : "Based on our knowledge base regarding \"{$top->title}\":\n\n";

        return $prefix.$top->content;
    }
}
