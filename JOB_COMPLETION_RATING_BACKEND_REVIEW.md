# Backend Review – Job Completion & Rating System

Review of the current backend against **Backend Requirements – Job Completion & Rating System**.

---

## 1. Endpoints

| Spec | Endpoint | Status | Notes |
|------|----------|--------|--------|
| POST | `/api/supply-jobs/:id/complete` | ✅ Done | `SupplyJobController::complete` – sets status, emails renter |
| POST | `/api/supply-jobs/:id/rate` | ❌ Missing | Renter should rate **this provider** (per supply job) |
| POST | `/api/supply-jobs/:id/rate/skip` | ❌ Missing | Renter skips rating for this provider |
| POST | `/api/supply-jobs/:id/rating-reply` | ✅ Done | `SupplyJobController::ratingReply` – provider reply |
| POST | `/api/rental-jobs/:id/rate` | ✅ Done | `RentalJobActionsController::rate` – job-level rating |
| POST | `/api/rental-jobs/:id/rate/skip` | ✅ Done | `RentalJobActionsController::rateSkip` – job-level skip |

**Action:** Add `POST /api/supply-jobs/:id/rate` and `POST /api/supply-jobs/:id/rate/skip`. With the current “one rating per rental job” schema, these can delegate to the rental job (resolve rental job from supply job, then apply same logic as rental rate/skip) so the frontend can call per–supply-job URLs. True “one rating per supply job” would require a schema change (see §5).

---

## 2. Response shape

- Success: `{ "success": true, "message": "...", "data": { ... } }` – ✅ Used consistently.
- Errors: `{ "success": false, "message": "..." }` with 400/403/404 – ✅ Used.

No change needed.

---

## 3. Status values

- API validation and DB (after migration) support: `accepted`, `partially_accepted`, `completed_pending_rating`, `rated`, `closed`, plus `pending`, `negotiating`, `cancelled`, `completed`.
- **Bug:** `cancelSupplyJob` was setting `status = 'Cancelled'` (capital C). Spec and filters use `cancelled`. **Fixed:** code now uses `'cancelled'`. If you have existing rows with `Cancelled`, run: `UPDATE supply_jobs SET status = 'cancelled' WHERE status = 'Cancelled';`

---

## 4. GET responses

### 4.1 Supply job list – `GET /api/supply-jobs`

- Each row has: `id`, `name`, `status`, `rental_job_id`, `start_date`, `end_date`, `products`. ✅
- **Missing:** `renter_company_name` (optional per spec).
- **Missing:** When `status === 'rated'`, include **`job_rating`** with shape:
  `{ "rating", "comment", "rated_at", "provider_reply", "provider_replied_at" }`.
- List does not eager-load `rentalJob.jobRating` or `ratingReply`, so `job_rating` cannot be built today. **Fix:** Eager-load and add `job_rating` for rows with status `rated`.

### 4.2 Supply job detail – `GET /api/supply-jobs/:id`

- When status is `rated`, **`job_rating`** is included with the correct shape (including `provider_reply`, `provider_replied_at`). ✅
- Detail uses `rentalJob.jobRating` and `ratingReply`; shape matches spec.

### 4.3 Rental job list – `GET /api/rental-jobs`

- Returns `id`, `name`, `status`, `from_date`, `to_date`, `products`, and when rated **`job_rating`** (with `provider_reply` / `provider_replied_at`). ✅

### 4.4 Rental job detail – `GET /api/rental-jobs/:id`

- **`suppliers`** array present with `supply_job_id`, `company_id`, `company_name`, `status`. ✅
- **Missing:** Per-supplier **`supplier_rating`** (same shape as `job_rating`). Spec: “Add supplier_rating when that provider has been rated.” **Fix:** For each supplier (supply job), derive `supplier_rating` from the rental job’s `job_rating` plus that supply job’s reply (from `job_rating.replies`).

---

## 5. Rating storage model

- **Current:** One rating per **rental job** (`job_ratings.rental_job_id` unique). One reply per **supply job** (`job_rating_replies.supply_job_id` unique).
- **Spec:** “One rating per supply job (per provider per job).”
- **Gap:** To support true per-provider rating (renter rates Company A and Company B separately), you’d need either:
  - A migration: e.g. add `supply_job_id` to `job_ratings` and store one rating per supply job (and optionally keep a single “job-level” rating for legacy), or
  - A new table (e.g. `supply_job_ratings`) keyed by `supply_job_id`.
- **Short-term:** Add `POST /api/supply-jobs/:id/rate` and `rate/skip` that resolve the rental job and call the same job-level rating/skip logic. That satisfies the endpoint list without a schema change; multi-provider jobs will still share one rating until the schema is updated.

---

## 6. Authorization

- **Provider** (`complete`, `rating-reply`): Check `user->company_id === supply_job->provider_id` or admin. ✅
- **Renter** (`rate`, `rate/skip` on rental job): Check rental job’s user’s company matches current user’s company or admin. ✅
- **Renter** (supply-job rate/skip once added): Must ensure current user’s company is the renter of the rental job that owns the supply job. ✅ (same check via rental job.)

No change needed once supply-job rate/skip are implemented with that check.

---

## 7. Background jobs

- “2 days after Unpack Date: remind provider to mark job completed” – ❌ Not implemented.
- “Every 7 days after rating request: remind renter to rate” – ❌ Not implemented.

Implement with Laravel scheduled jobs + notifications when required.

---

## 8. Request bodies

- `POST .../complete`: no body. ✅
- `POST .../rate`: `{ "rating": 1–5, "comment": "optional" }`. ✅ Validated.
- `POST .../rate/skip`: no body. ✅
- `POST .../rating-reply`: `{ "reply": "string" }`. ✅ Validated.

Dates in responses use ISO 8601 (e.g. `toIso8601String()`). ✅

---

## 9. rateSkip behavior

- **Current:** `rateSkip` only sets `skipped_at` on the rating; it does **not** set rental job or supply jobs status to `rated`. The job stays `completed_pending_rating`, so the renter can keep seeing “rate or skip.”
- **Recommendation:** After skip, set rental job and related supply jobs to `status = 'rated'` (or a dedicated “skipped” if you add it) so the flow is considered done and the UI can stop prompting.

---

## Summary of code changes applied

1. **Supply job list:** Eager-load `rentalJob.jobRating`, `ratingReply`, and renter company; when `status === 'rated'`, add `job_rating`; added optional `renter_company_name`.
2. **Rental job detail:** For each supplier, add `supplier_rating` (same shape as `job_rating`) from job rating + that supply job’s reply.
3. **New routes:** `POST /api/supply-jobs/:id/rate` and `POST /api/supply-jobs/:id/rate/skip` (delegate to rental job logic; auth = renter of the rental job).
4. **rateSkip (rental and supply):** After skip, set rental job and supply jobs to `status = 'rated'` so the flow is complete; response includes `data: {}`.
5. **cancelSupplyJob:** Status is now `'cancelled'` (lowercase).

Optional later: schema + logic for true “one rating per supply job” and background reminder jobs (§7).
