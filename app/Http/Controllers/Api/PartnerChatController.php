<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListPartnerChatConversationsRequest;
use App\Http\Requests\StorePartnerChatMessageRequest;
use App\Models\Company;
use App\Services\PartnerChatService;
use Illuminate\Http\JsonResponse;

class PartnerChatController extends Controller
{
    public function __construct(
        private readonly PartnerChatService $partnerChatService,
    ) {}

    /**
     * GET /api/v1/partner/chat/conversations
     */
    public function conversations(ListPartnerChatConversationsRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $perPage = (int) ($request->validated()['per_page'] ?? config('app.admin_list_per_page', 25));
        $result = $this->partnerChatService->listConversations($company, $perPage);

        return response()->json([
            'success' => true,
            'message' => 'Conversations fetched successfully.',
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    /**
     * POST /api/v1/partner/chat/messages
     */
    public function store(StorePartnerChatMessageRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $result = $this->partnerChatService->ingest($company, $request->validated());

        return response()->json($result['body'], $result['status']);
    }

    private function company(ListPartnerChatConversationsRequest|StorePartnerChatMessageRequest $request): Company
    {
        $company = $request->attributes->get('provider_company');
        if (! $company instanceof Company) {
            abort(response()->json([
                'success' => false,
                'message' => 'Provider company context is missing.',
            ], 403));
        }

        return $company;
    }
}
