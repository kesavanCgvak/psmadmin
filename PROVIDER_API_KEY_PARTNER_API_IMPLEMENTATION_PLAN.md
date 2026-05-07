# Provider API Key + Partner Product APIs Implementation Plan

## Goal

Implement a provider-facing API key system so a provider user can generate and copy an API key from frontend, then use that key in third-party applications to access:

1. Product search API
2. Product details API (by product id)

This document follows secure API key handling and rollout in phases.

## Functional Requirements

- Provider user clicks `Generate API Key` button in frontend.
- System generates a secure API key and stores only its hash.
- API key is shown once in UI for copy.
- Partner APIs authenticate using this API key.
- APIs return provider-scoped products only.
- Provider can revoke/rotate keys.

## Proposed API Endpoints

### Provider Key Management (Authenticated Provider Session)

- `POST /provider/api-keys/generate`
  - Generate new API key.
  - Returns plaintext key only once.

- `GET /provider/api-keys`
  - List keys for provider (`prefix`, `status`, `created_at`, `last_used_at`).

- `POST /provider/api-keys/{id}/revoke`
  - Revoke a key.

- Optional: `POST /provider/api-keys/{id}/rotate`
  - Create replacement key and revoke old key.

### Third-Party Partner APIs (API Key Auth)

- `GET /api/v1/partner/products/search`
  - Query params: `q`, `category`, `page`, `per_page`, `sort`.
  - Returns paginated provider-scoped products.

- `GET /api/v1/partner/products/{product_id}`
  - Returns product details for specified product id if accessible by provider.

## Database Design

Create table: `provider_api_keys`

- `id` (bigint, PK)
- `provider_user_id` (FK to users/providers table)
- `name` (nullable, key label)
- `key_prefix` (varchar 12-20, for display/audit)
- `key_hash` (char 64/128 depending on hash strategy)
- `is_active` (boolean default true)
- `last_used_at` (nullable datetime)
- `expires_at` (nullable datetime)
- `revoked_at` (nullable datetime)
- `created_at`, `updated_at`

Indexes:

- Index on `provider_user_id`
- Index on `is_active`
- Index on `key_prefix`
- Optional composite index `(provider_user_id, is_active)`

## Key Generation and Storage Strategy

- Key format example: `psm_pk_<high_entropy_random>`
- Generate using cryptographically secure random bytes.
- Store only hash (`key_hash`) in DB.
- Store `key_prefix` (first 6-8 chars) for display and troubleshooting.
- Return plaintext key only on creation/rotation response.
- Never log plaintext key in server logs.

## Authentication Middleware / Filter

Create dedicated API key auth middleware for partner APIs:

- Extract key from:
  - `Authorization: Bearer <API_KEY>` (preferred), or
  - `X-API-KEY: <API_KEY>` (optional fallback)
- Validate key format early.
- Hash incoming key and match against active non-revoked key.
- Reject expired/revoked/inactive keys.
- Inject provider context into request lifecycle.
- Update `last_used_at` after successful authentication.

Error responses:

- `401 Unauthorized`: missing/invalid API key
- `403 Forbidden`: revoked/inactive/expired key
- `429 Too Many Requests`: rate limited

## Partner API Contract

Use consistent JSON response envelope:

- success:
  - `success: true`
  - `message`
  - `data`
  - `meta` (pagination, request id, etc.)

- error:
  - `success: false`
  - `message`
  - `errors` (optional field-level details)

### Search API (`GET /api/v1/partner/products/search`)

Request:

- `q` (string, optional)
- `category` (string/int, optional)
- `page` (int, default from common config)
- `per_page` (int, default from common config, max capped)
- `sort` (optional)

Response:

- Product list with lightweight fields for listing
- Pagination meta (`page`, `per_page`, `total`, `total_pages`)

### Product Details API (`GET /api/v1/partner/products/{product_id}`)

Request:

- `product_id` in path

Response:

- Full product details allowed for provider scope
- Return `404` if product not found or not accessible

## API Usage Examples (Ready to Share)

Use your environment base URL:

- `BASE_URL=http://localhost:8000`

### 1) Generate Provider API Key (logged-in provider)

Endpoint:

- `POST {BASE_URL}/api/provider/api-keys/generate`

Headers:

- `Authorization: Bearer <PROVIDER_JWT_TOKEN>`
- `Content-Type: application/json`

Request body (optional):

```json
{
  "name": "Default key"
}
```

curl:

```bash
curl --request POST "${BASE_URL}/api/provider/api-keys/generate" \
  --header "Authorization: Bearer <PROVIDER_JWT_TOKEN>" \
  --header "Content-Type: application/json" \
  --data "{\"name\":\"Default key\"}"
```

Success response (example):

```json
{
  "success": true,
  "message": "API key generated successfully. Save it now, it will not be shown again.",
  "data": {
    "api_key": "psm_pk_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX",
    "key_prefix": "psm_pk_XXXXXXX"
  }
}
```

### 2) List Provider API Keys

Endpoint:

- `GET {BASE_URL}/api/provider/api-keys`

Headers:

- `Authorization: Bearer <PROVIDER_JWT_TOKEN>`

curl:

```bash
curl --request GET "${BASE_URL}/api/provider/api-keys" \
  --header "Authorization: Bearer <PROVIDER_JWT_TOKEN>"
```

### 3) Revoke Provider API Key

Endpoint:

- `POST {BASE_URL}/api/provider/api-keys/{id}/revoke`

Headers:

- `Authorization: Bearer <PROVIDER_JWT_TOKEN>`

curl:

```bash
curl --request POST "${BASE_URL}/api/provider/api-keys/1/revoke" \
  --header "Authorization: Bearer <PROVIDER_JWT_TOKEN>"
```

### 4) Partner Product Search (third-party app)

Endpoint:

- `GET {BASE_URL}/api/v1/partner/products/search?q=<keyword>&page=1&per_page=25`

Headers:

- `Authorization: Bearer <PROVIDER_API_KEY>`

curl:

```bash
curl --request GET "${BASE_URL}/api/v1/partner/products/search?q=bose&page=1&per_page=25" \
  --header "Authorization: Bearer <PROVIDER_API_KEY>"
```

Success response (example):

```json
{
  "success": true,
  "message": "Products fetched successfully.",
  "data": [
    {
      "product_id": 123,
      "product_name": "Bose 802",
      "model_name": "802",
      "psm_code": "PSM00012",
      "brand_id": 4,
      "brand_name": "Bose",
      "category_id": 2,
      "category_name": "Audio",
      "sub_category_id": 7,
      "sub_category_name": "Speaker",
      "quantity": 10,
      "rental_price": "100.00",
      "software_code": "FLEX-001"
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 25,
    "total": 1,
    "total_pages": 1
  }
}
```

### 5) Partner Product Details by Product ID (third-party app)

Endpoint:

- `GET {BASE_URL}/api/v1/partner/products/{product_id}`

Headers:

- `Authorization: Bearer <PROVIDER_API_KEY>`

curl:

```bash
curl --request GET "${BASE_URL}/api/v1/partner/products/123" \
  --header "Authorization: Bearer <PROVIDER_API_KEY>"
```

Success response (example):

```json
{
  "success": true,
  "message": "Product details fetched successfully.",
  "data": {
    "product_id": 123,
    "product_name": "Bose 802",
    "model_name": "802",
    "psm_code": "PSM00012",
    "webpage_url": null,
    "is_verified": 1,
    "height": null,
    "width": null,
    "length": null,
    "weight": null,
    "linear_unit_id": null,
    "weight_unit_id": null,
    "replacement_price": null,
    "source": null,
    "country_of_origin": null,
    "iso_code_2": null,
    "iso_code_3": null,
    "hsn_code": null,
    "brand_id": 4,
    "brand_name": "Bose",
    "category_id": 2,
    "category_name": "Audio",
    "sub_category_id": 7,
    "sub_category_name": "Speaker",
    "quantity": 10,
    "rental_price": "100.00",
    "software_code": "FLEX-001"
  }
}
```

### Error response examples

Missing or invalid key:

```json
{
  "success": false,
  "message": "Invalid API key."
}
```

Product not found for provider:

```json
{
  "success": false,
  "message": "Product not found."
}
```

## Frontend UX Flow (Provider)

- Place section in provider profile/settings/integrations page:
  - `Generate API Key` button
  - Existing keys list (prefix, status, created, last used)
  - `Revoke` action

On generate:

1. Call generation endpoint.
2. Show modal with one-time key display.
3. Provide `Copy` button and warning text:
   - "This key is shown only once. Save it securely."

Styling and scripts:

- Use common/global CSS and JS files.
- Avoid inline styles/scripts.
- Keep mobile responsive layout and consistent project typography.

## Security Controls

- Hash-only storage for API keys.
- Constant-time comparison for hash validation.
- Rate limiting per API key + IP.
- Audit log events:
  - key created
  - key revoked
  - key used (optional sampled logs)
- Mask keys in logs/monitoring output.
- Enforce HTTPS for partner API traffic.
- Optional future enhancement: IP allowlist per key.

## Validation and Authorization Rules

- Only authenticated provider users can generate/revoke keys.
- Provider can only access own keys.
- Partner APIs must always filter data by provider context from API key.
- Do not trust user-provided provider ids in query/body for partner APIs.

## Testing Plan

### Unit Tests

- Key generation entropy and format checks.
- Hash creation and verify logic.
- Middleware authentication edge cases.

### Feature/API Tests

- Generate key success/failure paths.
- Search endpoint with valid key.
- Search endpoint with invalid/revoked/expired key.
- Product details endpoint authorization and not found cases.
- Rate-limit behavior.

### Frontend Tests

- Generate button flow.
- One-time key display and copy action.
- Revoke key confirmation and state update.

## Rollout Plan

### Phase 1

- DB migration + model/repository for API keys
- Generate/list/revoke provider endpoints
- Provider UI for key generation and copy

### Phase 2

- API key middleware/filter
- Product search partner endpoint

### Phase 3

- Product details partner endpoint
- API documentation page for providers with sample requests

### Phase 4

- Security hardening (advanced rate limits, IP allowlist, improved audit dashboards)

## Open Decisions (Confirm Before Build)

1. Single active key per provider or multiple active keys?
2. Key expiry required or no expiry by default?
3. Exact product fields exposed to third-party clients?
4. Final rate-limit policy (per minute/per hour)?
5. Should rotate create new key and auto-revoke old immediately or after grace period?

## Suggested Next Step

After approval of open decisions, implement in this order:

1. migration + key service
2. provider key management endpoints
3. partner auth middleware
4. search API
5. details API
6. docs + test coverage

