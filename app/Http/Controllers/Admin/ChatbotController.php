<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotConversation;
use App\Services\Chatbot\ChatbotService;
use App\Services\InventoryAi\AiProviderFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ChatbotController extends Controller
{
    public function __construct(
        private readonly ChatbotService $chatbotService,
    ) {}

    public function index()
    {
        $aiSummary = AiProviderFactory::activeProviderSummary();

        return view('admin.chatbot.chat', compact('aiSummary'));
    }

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:4000',
            'conversation_id' => 'nullable|integer|exists:chatbot_conversations,id',
        ]);

        $user = $request->user();

        try {
            if (!empty($validated['conversation_id'])) {
                $conversation = ChatbotConversation::query()->findOrFail($validated['conversation_id']);

                if ($conversation->source !== ChatbotConversation::SOURCE_ADMIN
                    || (int) $conversation->user_id !== (int) $user->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid conversation.',
                    ], 403);
                }
            } else {
                $conversation = $this->chatbotService->startConversation(
                    $user,
                    ChatbotConversation::SOURCE_ADMIN,
                );
            }

            $result = $this->chatbotService->sendMessage(
                $conversation,
                $validated['message'],
                $user,
            );

            return response()->json([
                'success' => true,
                'message' => 'Reply generated.',
                'data' => [
                    'conversation_id' => $result['conversation']->id,
                    'user_message' => [
                        'id' => $result['user_message']->id,
                        'role' => $result['user_message']->role,
                        'content' => $result['user_message']->content,
                        'created_at' => $result['user_message']->created_at?->format(config('app.datetime_format')),
                    ],
                    'assistant_message' => [
                        'id' => $result['assistant_message']->id,
                        'role' => $result['assistant_message']->role,
                        'content' => $result['assistant_message']->content,
                        'created_at' => $result['assistant_message']->created_at?->format(config('app.datetime_format')),
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Unable to process chatbot message right now.',
            ], 500);
        }
    }
}
