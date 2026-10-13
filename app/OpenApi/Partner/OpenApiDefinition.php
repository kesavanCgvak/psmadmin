<?php

namespace App\OpenApi\Partner;

use OpenApi\Attributes as OA;

#[OA\OpenApi(
    openapi: OA\OpenApi::VERSION_3_0_0,
    info: new OA\Info(
        title: 'PSM Partner Open API',
        version: '1.0.0',
        description: <<<'DESC'
Partner APIs for provider companies.

**Getting started**
1. Log in as a provider user and generate an API key (`psm_pk_...`).
2. Ensure the company has **Open API Access** enabled by an administrator.
3. Send the API key on each request using `Authorization: Bearer {api_key}` (preferred) or `X-API-KEY: {api_key}`.

**Scopes**
Keys created before scopes existed, and keys generated without a `scopes` value, can call product endpoints only.
Chat endpoints require a key whose scopes include `chat`. Generate one with `POST /api/provider/api-keys/generate` and `"scopes": ["chat"]` or `"scopes": ["products", "chat"]`.
Generating a key revokes the previous active key for that user. Include every scope the new key should keep.

Product results and chat conversations are limited to the provider company linked to the API key.
DESC
    ),
    servers: [
        new OA\Server(
            url: L5_SWAGGER_CONST_HOST,
            description: 'API server'
        ),
    ],
    security: [['partnerApiKey' => []]],
    tags: [
        new OA\Tag(
            name: 'Partner Products',
            description: 'Search and retrieve provider-scoped product inventory.'
        ),
        new OA\Tag(
            name: 'Partner Chat',
            description: 'Submit inbound company-to-company chat messages and list conversations the authenticated company can map.'
        ),
    ],
    components: new OA\Components(
        securitySchemes: [
            new OA\SecurityScheme(
                securityScheme: 'partnerApiKey',
                type: 'http',
                scheme: 'bearer',
                bearerFormat: 'API Key',
                description: 'Provider API key. Format: `psm_pk_...`. Chat routes require the `chat` scope. Product routes accept legacy keys and keys that include the `products` scope. Alternative header: `X-API-KEY`.'
            ),
        ],
        schemas: [
            new OA\Schema(
                schema: 'ApiError',
                type: 'object',
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: false),
                    new OA\Property(property: 'message', type: 'string', example: 'Invalid API key.'),
                ]
            ),
            new OA\Schema(
                schema: 'PartnerProductMinimal',
                type: 'object',
                properties: [
                    new OA\Property(property: 'product_id', type: 'integer', example: 101),
                    new OA\Property(property: 'psm_code', type: 'string', example: 'PSM-001'),
                    new OA\Property(property: 'product_name', type: 'string', example: 'Sony FX6'),
                ]
            ),
            new OA\Schema(
                schema: 'PartnerProductSummary',
                type: 'object',
                properties: [
                    new OA\Property(property: 'product_id', type: 'integer', example: 101),
                    new OA\Property(property: 'product_name', type: 'string', example: 'Sony FX6'),
                    new OA\Property(property: 'model_name', type: 'string', example: 'FX6'),
                    new OA\Property(property: 'psm_code', type: 'string', example: 'PSM-001'),
                    new OA\Property(property: 'brand_id', type: 'integer', nullable: true, example: 12),
                    new OA\Property(property: 'brand_name', type: 'string', nullable: true, example: 'Sony'),
                    new OA\Property(property: 'category_id', type: 'integer', nullable: true, example: 3),
                    new OA\Property(property: 'category_name', type: 'string', nullable: true, example: 'Cameras'),
                    new OA\Property(property: 'sub_category_id', type: 'integer', nullable: true, example: 8),
                    new OA\Property(property: 'sub_category_name', type: 'string', nullable: true, example: 'Cinema'),
                    new OA\Property(property: 'quantity', type: 'integer', example: 4),
                    new OA\Property(property: 'rental_price', type: 'number', format: 'float', nullable: true, example: 250.00),
                    new OA\Property(property: 'software_code', type: 'string', nullable: true, example: 'FX6-01'),
                ]
            ),
            new OA\Schema(
                schema: 'PartnerProductDetail',
                allOf: [
                    new OA\Schema(ref: '#/components/schemas/PartnerProductSummary'),
                    new OA\Schema(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'webpage_url', type: 'string', nullable: true),
                            new OA\Property(property: 'is_verified', type: 'boolean', example: true),
                            new OA\Property(property: 'height', type: 'number', format: 'float', nullable: true),
                            new OA\Property(property: 'width', type: 'number', format: 'float', nullable: true),
                            new OA\Property(property: 'length', type: 'number', format: 'float', nullable: true),
                            new OA\Property(property: 'weight', type: 'number', format: 'float', nullable: true),
                            new OA\Property(property: 'linear_unit_id', type: 'integer', nullable: true),
                            new OA\Property(property: 'weight_unit_id', type: 'integer', nullable: true),
                            new OA\Property(property: 'replacement_price', type: 'number', format: 'float', nullable: true),
                            new OA\Property(property: 'source', type: 'string', nullable: true),
                            new OA\Property(property: 'country_of_origin', type: 'string', nullable: true),
                            new OA\Property(property: 'iso_code_2', type: 'string', nullable: true),
                            new OA\Property(property: 'iso_code_3', type: 'string', nullable: true),
                            new OA\Property(property: 'hsn_code', type: 'string', nullable: true),
                        ]
                    ),
                ]
            ),
            new OA\Schema(
                schema: 'PartnerChatMessageRequest',
                type: 'object',
                required: ['external_system', 'external_message_id', 'external_conversation_id', 'body'],
                properties: [
                    new OA\Property(property: 'external_system', type: 'string', example: 'rentman', description: 'Stable system slug. Allowed values: rentman, flex.'),
                    new OA\Property(property: 'external_message_id', type: 'string', maxLength: 191, example: 'external-message-123'),
                    new OA\Property(property: 'external_conversation_id', type: 'string', maxLength: 191, example: 'external-conversation-456'),
                    new OA\Property(property: 'psm_conversation_id', type: 'integer', nullable: true, example: 123, description: 'Required until this external conversation id is mapped. Must be a conversation the authenticated company belongs to.'),
                    new OA\Property(property: 'body', type: 'string', minLength: 1, maxLength: 2000, example: 'Hello, we have received your rental request.'),
                    new OA\Property(property: 'external_created_at', type: 'string', format: 'date-time', nullable: true, example: '2026-10-12T10:30:00Z', description: 'Stored on the external message link. Unread state uses the PSM message timestamp.'),
                ]
            ),
            new OA\Schema(
                schema: 'PartnerChatMessage',
                type: 'object',
                properties: [
                    new OA\Property(property: 'id', type: 'integer', example: 55, description: 'PSM chat message id.'),
                    new OA\Property(property: 'conversation_id', type: 'integer', example: 123),
                    new OA\Property(property: 'external_system', type: 'string', example: 'rentman'),
                    new OA\Property(property: 'external_message_id', type: 'string', example: 'external-message-123'),
                    new OA\Property(property: 'external_conversation_id', type: 'string', example: 'external-conversation-456'),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-10-12T10:30:05+00:00'),
                ]
            ),
            new OA\Schema(
                schema: 'PartnerChatConversation',
                type: 'object',
                properties: [
                    new OA\Property(property: 'id', type: 'integer', example: 123),
                    new OA\Property(property: 'counterparty_company_id', type: 'integer', example: 45),
                    new OA\Property(property: 'counterparty_company_name', type: 'string', example: 'Northwind Rentals'),
                    new OA\Property(property: 'rental_job_id', type: 'integer', nullable: true, example: null),
                    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
                ]
            ),
            new OA\Schema(
                schema: 'ValidationError',
                type: 'object',
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: false),
                    new OA\Property(property: 'message', type: 'string', example: 'Validation error'),
                    new OA\Property(
                        property: 'errors',
                        type: 'object',
                        additionalProperties: new OA\AdditionalProperties(
                            type: 'array',
                            items: new OA\Items(type: 'string')
                        )
                    ),
                ]
            ),
            new OA\Schema(
                schema: 'PaginationMeta',
                type: 'object',
                properties: [
                    new OA\Property(property: 'page', type: 'integer', example: 1),
                    new OA\Property(property: 'per_page', type: 'integer', example: 25),
                    new OA\Property(property: 'total', type: 'integer', example: 120),
                    new OA\Property(property: 'total_pages', type: 'integer', example: 5),
                ]
            ),
        ]
    )
)]
class OpenApiDefinition {}
