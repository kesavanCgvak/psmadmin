# Admin overall company rating override (absolute)

## Goal

You already show a **consolidated (average) company rating** derived from user ratings, plus:

- **Star-wise breakdown** (5★/4★/3★/2★/1★ counts/percent)
- **Total users rated** (count)

Now admin wants a **single input** on `/admin/companies` (company list) to **increase or decrease the overall displayed rating** (e.g., set to `3.0` or `4.0`), without editing each job rating.

This document describes how to do that cleanly.

---

## Key rule (important)

- **Admin override is an absolute rating value**, not a delta.
  - Admin will enter `3`, `3.5`, `4.0`, etc.
  - Admin will **NOT** enter `+0.5` or `-1.0`.

---

## Recommended approach

### 1) Keep user rating data intact

Do **not** modify any raw rating rows (`job_ratings`, `renter_ratings`, `company_ratings`, etc.).

That raw data is still required for:

- star-wise breakdown
- “N users rated”
- audits/history

### 2) Add a separate “override” field on company

Store a manual admin override on the company record.

- If override is **NULL**: UI/API uses **calculated average** from user ratings.
- If override is **NOT NULL**: UI/API uses **override value** for the overall average rating display.

This gives admin a one-input control, while preserving user rating breakdown.

---

## Data model / DB design

### Add columns on `companies`

Suggested columns:

- `rating_override` (decimal 3,1 or 3,2) **nullable**
  - stores `3.5`, `4.0`, etc.
- `rating_override_set_by` (nullable FK `users.id`)
- `rating_override_reason` (nullable text)
- `rating_override_set_at` (nullable timestamp)

**Why nullable override?**

- NULL means “use system calculated rating”
- non-NULL means “admin decided the official displayed rating”

> Note: You already have a `companies.rating` column in your schema, but it’s ambiguous (sometimes used as stored rating, sometimes not). A dedicated `rating_override` field avoids confusion and makes intent clear.

---

## How the UI should behave

### Admin company list page (`/admin/companies`)

Add a small control per row:

- **Override input** (0.0–5.0, step 0.1)
- Save button (or auto-save on blur)

Also display:

- **Calculated rating** (from user ratings)
- **Displayed rating** (final)
  - If override exists: show override as displayed rating, and show a small “Overridden” badge.
  - If no override: displayed rating == calculated rating.

This makes it transparent to admins what’s happening.

### Company details page

Show:

- “Displayed rating” (override if exists else calculated)
- “Calculated rating” (from users)
- “N users rated”
- star-wise breakdown

---

## API behavior (details page)

### Requirement

You said: **don’t add new payload fields**; keep the existing response shape.

### Behavior

Keep the same JSON keys (example):

- `renter_company_rating`
- `renter_company_rating_count`

But compute `renter_company_rating` as:

1. Calculate **user-based average** (current logic)
2. If `companies.rating_override` is NOT NULL, replace the final value with the override
3. Return the final value in `renter_company_rating`

### Important note about star-wise breakdown

If the API/UI shows star-wise breakdown, it should still be computed from **raw ratings**.

Admin override affects only the **headline overall rating** number.

---

## Validation rules

- override must be numeric
- min `0`, max `5`
- allow 1 decimal (or 2 decimals if you prefer)
- if admin clears the field (empty): set it back to NULL (meaning “use calculated rating”)

---

## Audit trail (recommended)

Store:

- who set the override
- when it was set
- optional reason

This prevents “silent” changes and makes admin decisions traceable.

---

## Why this solves the requirement

- Admin can set one value (e.g. `3.0`) regardless of the calculated average (e.g. `4.5`)
- User rating breakdown and count remain consistent and honest
- API stays backward compatible (same keys), only the final value can differ when overridden

