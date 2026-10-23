<?php

namespace App\OpenApi\Partner;

use OpenApi\Attributes as OA;

/**
 * OpenAPI operation definitions for inbound partner chat.
 * Implementation lives in App\Http\Controllers\Api\PartnerChatController.
 */
class PartnerChatEndpoints
{
    #[OA\Post(
        path: '/api/v1/partner/chat/messages',
        operationId: 'partnerChatStoreMessage',
        summary: 'Submit an inbound chat message',
        description: <<<'DESC'
Creates a PSM chat message in a company-to-company conversation, or returns the existing message when the same external id is retried.

The API key identifies the sending company. That company must be a participant in the conversation. The sender user is the company's default contact, who must belong to that company and must not be blocked. The request cannot name a different sending company.

`external_conversation_id` is stored as a company-scoped mapping. The first request for an external conversation must include `psm_conversation_id`. Later requests may omit it. There is no matching by company name, contact name, or email.

Retries with the same `external_system` and `external_message_id` for the same conversation and body return HTTP 200 and do not create another message, broadcast, or notification. A reused external message id with a different body or conversation returns HTTP 409.

`external_created_at` is stored for reference. Unread counts use the PSM `created_at` of the chat message.

Rate limit: 60 requests per minute for the authenticated company.
DESC,
        security: [['partnerApiKey' => []]],
        tags: ['Partner Chat'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/PartnerChatMessageRequest')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Message created. Existing chat broadcast and notification behavior runs for this new message.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Message sent.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/PartnerChatMessage'),
                    ]
                )
            ),
            new OA\Response(
                response: 200,
                description: 'Idempotent retry. The original PSM message id is returned and no duplicate message is created.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Existing message returned.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/PartnerChatMessage'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Missing or invalid API key. A revoked key is treated as invalid.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
            new OA\Response(
                response: 403,
                description: 'Expired key, Open API disabled, non-provider account, API key not authorized for the partner APIs, or the conversation belongs to other companies.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
            new OA\Response(
                response: 404,
                description: 'PSM conversation was not found, or the external conversation has not been mapped yet.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
            new OA\Response(
                response: 409,
                description: 'The external message id was already used for a different body or conversation, or the external conversation id is mapped to a different PSM conversation.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
            new OA\Response(
                response: 422,
                description: 'Invalid fields, or the authenticated company has no valid default contact to use as the sender.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')
            ),
            new OA\Response(
                response: 429,
                description: 'Too many requests (rate limit: 60 requests per minute per company).',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
        ]
    )]
    public function store(): void {}

    #[OA\Get(
        path: '/api/v1/partner/chat/conversations',
        operationId: 'partnerChatListConversations',
        summary: 'List conversations for mapping',
        description: <<<'DESC'
Returns conversations that include the authenticated company, so an integrator can choose `psm_conversation_id` for the first message mapping.

The payload is limited to the conversation id, counterparty company id and name, rental job id, and updated time. Message history is not included; use `GET /api/v1/partner/chat/conversations/{conversation}` for one conversation and its messages. Conversations that do not include the authenticated company are omitted.

Uses the same provider API key as the other partner public APIs. Rate limit: 60 requests per minute for the authenticated company.
DESC,
        security: [['partnerApiKey' => []]],
        tags: ['Partner Chat'],
        parameters: [
            new OA\Parameter(
                name: 'page',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1, example: 1)
            ),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25, example: 25)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conversations fetched successfully.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Conversations fetched successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/PartnerChatConversation')
                        ),
                        new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Missing or invalid API key.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
            new OA\Response(
                response: 403,
                description: 'Open API disabled, expired key, invalid provider context, or an API key that is not authorized for the partner APIs.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
            new OA\Response(
                response: 422,
                description: 'Invalid pagination parameters.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')
            ),
            new OA\Response(
                response: 429,
                description: 'Too many requests (rate limit: 60 requests per minute per company).',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
        ]
    )]
    public function conversations(): void {}

    #[OA\Get(
        path: '/api/v1/partner/chat/conversations/{conversation}',
        operationId: 'partnerChatShowConversation',
        summary: 'Get one conversation and its messages',
        description: <<<'DESC'
Returns one conversation that includes the authenticated company, together with that conversation's messages.

Messages are newest first and paginated with `page` and `per_page`. A deleted message is included with an empty body and `is_deleted: true`. `external_system`, `external_message_id`, and `external_conversation_id` are present only when this company stored that link; another company's external ids are omitted.

An unknown id returns 404. A conversation that does not include the authenticated company returns 403. Uses the same provider API key as the other partner public APIs. Rate limit: 60 requests per minute for the authenticated company.
DESC,
        security: [['partnerApiKey' => []]],
        tags: ['Partner Chat'],
        parameters: [
            new OA\Parameter(
                name: 'conversation',
                in: 'path',
                required: true,
                description: 'PSM conversation id from the conversations list.',
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 123)
            ),
            new OA\Parameter(
                name: 'page',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1, example: 1)
            ),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25, example: 25)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conversation fetched successfully.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Conversation fetched successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/PartnerChatConversationDetail'),
                        new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Missing or invalid API key.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
            new OA\Response(
                response: 403,
                description: 'Open API disabled, expired key, invalid provider context, API key not authorized for the partner APIs, or the conversation belongs to other companies.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
            new OA\Response(
                response: 404,
                description: 'Conversation was not found.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
            new OA\Response(
                response: 422,
                description: 'Invalid pagination parameters.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')
            ),
            new OA\Response(
                response: 429,
                description: 'Too many requests (rate limit: 60 requests per minute per company).',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
        ]
    )]
    public function show(): void {}
}
