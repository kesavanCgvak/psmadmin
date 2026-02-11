# API Verification – Job Completion & Rating System

Verification of the backend against **API Details – Job Completion & Rating System**.  
Each section of the spec is checked and marked ✅ (done), ⚠️ (partial/note), or ❌ (gap).

---

## 1. Endpoints (§1)

| # | Method | Endpoint | Status | Notes |
|---|--------|----------|--------|--------|
| 1 | POST | `/api/supply-jobs/:id/complete` | ✅ | `SupplyJobController::complete` |
| 2 | POST | `/api/supply-jobs/:id/rate` | ✅ | `SupplyJobController::rate` |
| 3 | POST | `/api/supply-jobs/:id/rate/skip` | ✅ | `SupplyJobController::rateSkip` |
| 4 | POST | `/api/supply-jobs/:id/rating-reply` | ✅ | `SupplyJobController::ratingReply` |
| 5 | POST | `/api/rental-jobs/:id/rate` | ✅ | `RentalJobActionsController::rate` |
| 6 | POST | `/api/rental-jobs/:id/rate/skip` | ✅ | `RentalJobActionsController::rateSkip` |

Path params: `:id` is supply job ID (1–4) or rental job ID (5–6). ✅

---

## 2. Request Bodies (§2)

| Endpoint | Spec | Backend | Status |
|----------|------|---------|--------|
| POST .../complete | No body | No body used | ✅ |
| POST .../rate (supply & rental) | `{ "rating": 1–5, "comment": optional }` | `rating` required integer 1–5, `comment` nullable string max 2000 | ✅ |
| POST .../rate/skip | No body | No body used | ✅ |
| POST .../rating-reply | `{ "reply": required, non-empty after trim }` | `reply` required string max 2000; trim + empty check → 422 | ✅ |

---

## 3. Success Response Shape (§3)

- Format: `{ "success": true, "message": "...", "data": { ... } }`  
  All six endpoints return this. ✅
- `message`: Present on all. ✅
- `data`: Empty `{}` or with payload; frontend can refetch. ✅
- Errors: `{ "success": false, "message": "..." }` with 400/403/404. ✅

---

## 4. Status Values (§4)

- Exact strings used in responses and validation: `accepted`, `partially_accepted`, `completed_pending_rating`, `rated`, `closed` (plus `pending`, `negotiating`, `cancelled`, `completed` where applicable). ✅
- Supply job list: `?status=...` filter supported. ✅
- Rental job list: `?status=...` filter supported; each job has `status`. ✅

---

## 5. Data in GET Responses (§5)

### 5.1 Supply job list – `GET /api/supply-jobs`

| Field | Required | Backend | Status |
|-------|----------|---------|--------|
| `id` | Yes | ✅ | ✅ |
| `name` | Yes | ✅ | ✅ |
| `status` | Yes | ✅ | ✅ |
| `rental_job_id` | Yes | ✅ | ✅ |
| `start_date` | Yes (ISO 8601) | From `rentalJob.from_date` | ⚠️ See §9 |
| `end_date` | Yes (ISO 8601) | From `rentalJob.to_date` | ⚠️ See §9 |
| `products` | As needed | ✅ | ✅ |
| `renter_company_name` | No | ✅ When available | ✅ |
| **When status = rated:** `job_rating` | — | ✅ rating, comment, rated_at, provider_reply, provider_replied_at | ✅ |

Query params: `company_id` (required), `page`, `per_page`, `status`, `start_date`, `end_date`. ✅

### 5.2 Supply job detail – `GET /api/supply-jobs/:id`

- Same base fields as list + full detail (offers, negotiation_controls, products, etc.). ✅
- When status is `rated`: `job_rating` with same shape as §5.1. ✅

### 5.3 Rental job list – `GET /api/rental-jobs`

| Field | Required | Backend | Status |
|-------|----------|---------|--------|
| `id`, `name`, `status`, `from_date`, `to_date`, `products` | Yes | ✅ | ✅ |
| `job_rating` when rated (single provider) | For Rating column | ✅ Full shape including provider_reply, provider_replied_at | ✅ |
| `suppliers` on each row | Optional (multi-company) | Not returned | ⚠️ Optional per spec; list works with `job_rating` only |

Status filter supported. ✅

### 5.4 Rental job detail – `GET /api/rental-jobs/:id`

| Field | Required | Backend | Status |
|-------|----------|---------|--------|
| Usual rental job fields | — | id, name, status, from_date, to_date, delivery_address, products, etc. | ✅ |
| **suppliers** array | Yes | ✅ | ✅ |
| Each supplier: supply_job_id, rental_job_id, company_id, company_name, status | Yes | ✅ | ✅ |
| Each supplier: **supplier_rating** (when applicable) | Omit or null until rated | ✅ Added when job has rating; shape: rating, comment, rated_at, provider_reply, provider_replied_at | ✅ |
| Root **job_rating** (legacy) | Optional | ✅ Returned when job is rated | ✅ |

---

## 6. Rating Storage Model (§6)

- **Spec recommends:** One rating per supply job (per provider per rental job).
- **Current backend:** One rating per **rental job** (`job_ratings.rental_job_id` unique); one **reply** per supply job (`job_rating_replies.supply_job_id` unique).
- **Effect:** POST supply-jobs/:id/rate updates the single job-level rating and marks all related supply jobs as `rated`. Each supplier can have a different **provider_reply** (and provider_replied_at). True “rate provider A vs B separately” would require a schema change (e.g. rating keyed by supply_job_id). ⚠️ Documented in JOB_COMPLETION_RATING_BACKEND_REVIEW.md.

---

## 7. Authorization (§7)

- **Provider** (complete, rating-reply): User’s company must own the supply job (`provider_id`). Enforced. ✅
- **Renter** (rate, rate/skip): User’s company must be the renter of the rental job (rental job’s user’s company). Enforced for both rental-job and supply-job endpoints. ✅

---

## 8. Validation / Business Rules (§8)

| Endpoint | Rule | Backend | Status |
|----------|------|---------|--------|
| POST .../complete | Only `accepted` or `partially_accepted` | 400 with message if not | ✅ |
| POST .../rate | rating 1–5; job in ratable state | 1–5 validated; 400 if not `completed_pending_rating` | ✅ |
| POST .../rate/skip | Job in `completed_pending_rating` | 400 if not | ✅ |
| POST .../rating-reply | Supply job `rated`, rating exists, reply non-empty (trim) | Status check + rating existence + trim + empty → 422 | ✅ |

---

## 9. Date Format (§9)

- **Spec:** All dates in responses = ISO 8601 (e.g. `"2025-02-02T14:30:00.000Z"`).
- **Backend:**  
  - `rated_at`, `provider_replied_at`: Explicitly formatted with `->toIso8601String()`. ✅  
  - **List/detail date fields** (`start_date`, `end_date`, `from_date`, `to_date`): Returned as model attributes (Laravel serializes date columns; format is typically `Y-m-d` or full ISO depending on column type). ⚠️ If the frontend expects **full** ISO 8601 datetime for these, format them explicitly (e.g. `Carbon::parse($date)->toIso8601String()` or same for date-only).

---

## 10. Background Jobs (§10)

- Reminder to **provider** 2 days after Unpack Date: ❌ Not implemented.
- Reminder to **renter** every 7 days after rating request: ❌ Not implemented.

(No REST API required; implement via Laravel scheduler + notifications when needed.)

---

## 11. Quick Checklist (§11)

| Item | Status |
|------|--------|
| POST supply-jobs/:id/complete (no body) | ✅ |
| POST supply-jobs/:id/rate with { rating, comment } | ✅ |
| POST supply-jobs/:id/rate/skip (no body) | ✅ |
| POST supply-jobs/:id/rating-reply with { reply } | ✅ |
| POST rental-jobs/:id/rate and /rate/skip | ✅ |
| Supply list/detail: status; when rated → job_rating (full shape) | ✅ |
| Rental detail: suppliers with supply_job_id, company_name, status, supplier_rating | ✅ |
| Rental list: job_rating for Rating column (optional suppliers not added) | ✅ |
| Exact status strings | ✅ |
| Dates ISO 8601 | ✅ (explicit for rating/reply; ⚠️ list dates see §9) |

---

## Summary

- **Endpoints, request/response shapes, status values, authorization, and validation** match the spec.
- **Supply and rental list/detail** return the required fields and `job_rating` / `supplier_rating` shapes when rated.
- **Optional:** Rental list does not return `suppliers` per row (spec allows this).
- **Note:** List/detail **date** fields (`start_date`, `end_date`, `from_date`, `to_date`) are not explicitly formatted to ISO 8601 in code; if the frontend requires a strict format, format them in the controllers.
- **Rating model:** One rating per rental job with per–supply-job replies; true “one rating per supply job” would need a DB/schema change.
- **Background reminder jobs** are not implemented.

No blocking gaps for the current frontend flow; only optional enhancements and date-format hardening if needed.
