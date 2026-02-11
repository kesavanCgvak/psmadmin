# Frontend Backend Compliance Checklist

This document verifies the backend against **What the Frontend Needs from the Backend** (job completion & rating flows).

---

## 1. Endpoints ✅

| Method | Endpoint | Who | Backend |
|--------|----------|-----|---------|
| POST | `/api/supply-jobs/:id/complete` | Provider | ✅ `SupplyJobController::complete` – sets `completed_pending_rating`, `completed_at`, emails renter |
| POST | `/api/supply-jobs/:id/rate` | Renter | ✅ Body: `rating` (1–5), `comment` (optional). Auth: renter company |
| POST | `/api/supply-jobs/:id/rate/skip` | Renter | ✅ No body. Auth: renter company |
| POST | `/api/supply-jobs/:id/rating-reply` | Provider | ✅ Body: `reply`. Auth: provider company |
| POST | `/api/rental-jobs/:id/rate` | Renter | ✅ Legacy – `RentalJobActionsController::rate` |
| POST | `/api/rental-jobs/:id/rate/skip` | Renter | ✅ Legacy – `RentalJobActionsController::rateSkip` |

- Success: `{ "success": true, "message": "...", "data": {} }`
- Error: `{ "success": false, "message": "..." }` with 400/403/404

---

## 2. Status values ✅

Supported in lists/detail and filters:

- `accepted`, `partially_accepted`, `completed_pending_rating`, `rated`, `closed`
- Plus: `pending`, `negotiating`, `cancelled`, `completed` (supply); `open`, `in_negotiation`, `completed`, `cancelled` (rental)

**Filter:** `GET /api/supply-jobs?status=...` and `GET /api/rental-jobs?status=...` both support `?status=...`.

---

## 3. Rental job status with multiple providers ✅ (fixed)

- **Rule:** Rental job = `completed_pending_rating` only when **all** accepted providers have marked completed.
- **Rule:** Rental job = `rated` only when **all** (accepted) providers have been rated (or skipped).

**Backend:**

- **complete():** After marking one supply job completed, rental job is set to `completed_pending_rating` only if no other supply job for that rental is still `accepted` or `partially_accepted`. Otherwise rental stays `partially_accepted`.
- **rate() / rateSkip():** When the last supply job for that rental moves to `rated`, rental job is set to `rated`.

---

## 4. Supply job list – `GET /api/supply-jobs` ✅

- **Query params:** `company_id` (required), `page`, `per_page`, `status`, `start_date`, `end_date`.
- **Each row:** `id`, `name`, `status`, `rental_job_id`, `start_date`, `end_date`, `products`, `renter_company_name` (optional).
- **When status is `rated`:** includes **`job_rating`** with:
  - `rating`, `comment`, `rated_at` (ISO 8601), `provider_reply`, `provider_replied_at`

---

## 5. Supply job detail – `GET /api/supply-jobs/:id` ✅

- Same as list plus: full product/offer/milestone data, `negotiation_controls`, company/currency.
- When status is `rated`, includes **`job_rating`** (same shape as above).

---

## 6. Rental job list – `GET /api/rental-jobs` ✅

- **Each row:** `id`, `name`, `status`, `from_date`, `to_date`, `products`, `suppliers`, etc.
- **`suppliers`:** array of `supply_job_id`, **`company_id`**, `company_name`, `status`, **`supplier_rating`** (same shape as `job_rating`; only for that supply job; omitted if not rated).
- **Single-provider:** root **`job_rating`** also returned for backward compatibility.
- Rental job `status` is stored and updated when all providers are rated → `rated`.

---

## 7. Rental job detail – `GET /api/rental-jobs/:id` ✅

- **`suppliers`** array: `supply_job_id`, `rental_job_id`, `company_id`, `company_name`, `status`, **`supplier_rating`** (per supply job; omitted if not rated).
- Rating from `POST /api/supply-jobs/101/rate` appears only on the supplier with `supply_job_id: 101`.
- **Legacy:** For single-provider jobs, root **`job_rating`** is also returned.

---

## 8. Rating object shape ✅

| Field | Type | Backend |
|-------|------|---------|
| `rating` | number (1–5) | ✅ |
| `comment` | string \| null | ✅ |
| `rated_at` | string (ISO 8601) | ✅ `toIso8601String()` |
| `provider_reply` | string \| null | ✅ from `JobRatingReply` |
| `provider_replied_at` | string \| null | ✅ ISO 8601 |

---

## 9. Storage and authorization ✅

- **Storage:** One rating per supply job (`job_ratings.supply_job_id`). Reply in `job_rating_replies`.
- **Provider** (`complete`, `rating-reply`): user’s company must be the supply job’s `provider_id`.
- **Renter** (`rate`, `rate/skip`): user’s company must be the rental job’s creator company (`rental_job.user.company_id`).

---

## 10. Background (reminders)

| Spec | Backend implementation |
|------|-------------------------|
| **Email when provider marks completed:** notify **renter** to rate | ✅ Sent immediately when provider calls `POST /api/supply-jobs/:id/complete` – email `jobRatingRequest` |
| **2 days after Unpack Date:** remind **provider** to mark job completed | ✅ 2, 7, 14, 21, 30 days after unpack – command `supply-jobs:send-completion-reminders` (daily) |
| **Every 7 days after rating request:** remind **renter** to rate | ✅ Every 7 days: 7, 14, 21, 30 days after **completed** date – command `supply-jobs:send-renter-rating-reminders` (daily) |

**Note:** Renter reminders start at 7 days (not 2 days) and continue every 7 days up to 30 days.

---

## Summary

- All required endpoints are implemented with correct methods, auth, and response shape.
- Status values and filters are supported.
- Rental job status with multiple providers is correct (completed_pending_rating only when all completed; rated when all rated).
- Supply and rental list/detail include `job_rating` / `supplier_rating` with the agreed shape and ISO 8601 dates.
- Rental list suppliers include `company_id`.
- Reminder schedules: Provider = 2, 7, 14, 21, 30 days after unpack; Renter = 7, 14, 21, 30 days after completed (every 7 days).
- Initial email sent to renter immediately when provider marks job as completed.
