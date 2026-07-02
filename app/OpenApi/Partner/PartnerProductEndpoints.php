<?php

namespace App\OpenApi\Partner;

use OpenApi\Attributes as OA;

/**
 * OpenAPI operation definitions for partner product endpoints.
 * Implementation lives in App\Http\Controllers\Api\PartnerProductController.
 */
class PartnerProductEndpoints
{
    #[OA\Get(
        path: '/api/v1/partner/products/search',
        operationId: 'partnerProductsSearch',
        description: 'Search products in the authenticated provider company inventory.',
        summary: 'Search provider products',
        security: [['partnerApiKey' => []]],
        tags: ['Partner Products'],
        parameters: [
            new OA\Parameter(
                name: 'q',
                in: 'query',
                required: true,
                description: 'Search keyword (minimum 2 characters). Matches model, brand, category, and sub-category.',
                schema: new OA\Schema(type: 'string', minLength: 2, example: 'sony')
            ),
            new OA\Parameter(
                name: 'page',
                in: 'query',
                required: false,
                description: 'Page number for pagination.',
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1, example: 1)
            ),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                description: 'Number of items per page (max 100).',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25, example: 25)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Products fetched successfully.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Products fetched successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/PartnerProductSummary')
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
                description: 'Open API access disabled, expired key, or invalid provider context.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error (e.g. missing or short search query).',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
            new OA\Response(
                response: 429,
                description: 'Too many requests (rate limit: 60 requests per minute).',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
        ]
    )]
    public function search(): void
    {
    }

    #[OA\Get(
        path: '/api/v1/partner/products/{product_id}',
        operationId: 'partnerProductDetails',
        description: 'Get full details for a single product in the provider company inventory.',
        summary: 'Get product details',
        security: [['partnerApiKey' => []]],
        tags: ['Partner Products'],
        parameters: [
            new OA\Parameter(
                name: 'product_id',
                in: 'path',
                required: true,
                description: 'Product ID from search results.',
                schema: new OA\Schema(type: 'integer', example: 101)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Product details fetched successfully.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Product details fetched successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/PartnerProductDetail'),
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
                description: 'Open API access disabled, expired key, or invalid provider context.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
            new OA\Response(
                response: 404,
                description: 'Product not found in provider inventory.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
            new OA\Response(
                response: 429,
                description: 'Too many requests (rate limit: 60 requests per minute).',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError')
            ),
        ]
    )]
    public function details(): void
    {
    }
}
