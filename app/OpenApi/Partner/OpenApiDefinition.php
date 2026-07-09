<?php

namespace App\OpenApi\Partner;

use OpenApi\Attributes as OA;

#[OA\OpenApi(
    openapi: OA\OpenApi::VERSION_3_0_0,
    info: new OA\Info(
        title: 'PSM Partner Open API',
        version: '1.0.0',
        description: <<<'DESC'
Partner APIs for provider companies to search and retrieve product inventory via API key authentication.

**Getting started**
1. Log in to the provider dashboard and generate an API key (`psm_pk_...`).
2. Ensure your company has **Open API Access** enabled by an administrator.
3. Send the API key on each request using `Authorization: Bearer {api_key}` (preferred) or `X-API-KEY: {api_key}`.

Results are scoped to the provider company linked to the API key.
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
    ],
    components: new OA\Components(
        securitySchemes: [
            new OA\SecurityScheme(
                securityScheme: 'partnerApiKey',
                type: 'http',
                scheme: 'bearer',
                bearerFormat: 'API Key',
                description: 'Provider API key from the dashboard. Format: `psm_pk_...`. Alternative header: `X-API-KEY`.'
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
class OpenApiDefinition
{
}
