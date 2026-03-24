# Name Split Refactor Report: `full_name` → `first_name` + `last_name`

**Project:** PSM Admin Panel (Laravel Backend + Admin Panel + APIs)  
**Date:** March 20, 2025  
**Scope:** Split user profile full name into `first_name` and `last_name` across the entire system.

---

## Executive Summary

The system currently uses:
- **`users.name`** – nullable, legacy column (rarely used; most logic uses profile)
- **`user_profiles.full_name`** – primary source of user display name (single column)

The refactor will add `first_name` and `last_name` to `user_profiles`, keep `full_name` as a computed accessor for backward compatibility, and update all consumers.

---

## 1. All Files Using Name / Full Name

### 1.1 Direct `$user->name` or `users.name` Usage

| File | Line | Usage | Notes |
|------|------|-------|-------|
| `app/Http/Resources/UserResource.php` | 19 | `'name' => $this->name` | API response – returns `users.name` |
| `app/Http/Controllers/Api/CompanyUserController.php` | 379 | `'name' => $user->name` | makeAdmin response |
| `app/Http/Controllers/Api/UserProfileController.php` | 180 | `'username' => $user->name` | **BUG:** should be `$user->username` |
| `resources/views/profile/partials/update-profile-information-form.blade.php` | 18 | `$user->name` | Web profile form – uses `users.name` |
| `app/Http/Controllers/Api/RentalRequestController.php` | 213 | `$user->name` | Fallback in quote email |
| `app/Http/Controllers/Api/RentalRequestController - Copy.php` | 214 | `$user->name` | Same fallback |
| `app/Http/Controllers/Auth/RegisteredUserController.php` | 39 | `'name' => $request->name` | Web registration (creates user with `name`) |
| `tests/Feature/ProfileTest.php` | 41 | `$user->name` | Test assertion |

### 1.2 `user_profiles.full_name` Usage

| File | Line | Usage |
|------|------|-------|
| `app/Models/UserProfile.php` | 17 | `fillable` |
| `app/Models/User.php` | 99 | `$this->profile->full_name` (email verification) |
| `app/Models/Company.php` | 112 | `defaultContactProfile` selects `full_name` |
| `app/Http/Controllers/Api/UserProfileController.php` | 117, 227 | getProfile `name`, updateName |
| `app/Http/Controllers/Api/CompanyUserController.php` | 79, 179, 185, 245 | Create/update profile, API response |
| `app/Http/Controllers/Api/RentalRequestController.php` | 213 | `$user->profile->full_name ?? $user->name` |
| `app/Http/Controllers/Api/SupportRequestController.php` | 86, 104, 110 | Profile full_name |
| `app/Http/Controllers/Api/CommentController.php` | 45, 54 | Comment sender name |
| `app/Http/Controllers/Api/SubscriptionController.php` | 871 | Subscription response |
| `app/Http/Controllers/Api/CompanyController.php` | 346, 881, 999 | Contact name in company API |
| `app/Http/Controllers/Api/ProductController.php` | 759 | Product created notification |
| `app/Http/Controllers/Api/ForgotPasswordController.php` | 42, 48 | Password reset email |
| `app/Http/Controllers/Api/AuthController.php` | 152, 176 | Registration response |
| `app/Http/Controllers/Admin/UserManagementController.php` | 57, 119, 172, 256, 299 | Validation, create, update |
| `app/Http/Controllers/Admin/AdminUserManagementController.php` | 87, 133, 209, 248, 255 | Admin user CRUD |
| `app/Http/Controllers/Admin/SubscriptionManagementController.php` | 60 | Search by full_name |
| `app/Jobs/SyncUserToHubSpot.php` | 88 | HubSpot sync (already splits for first/last) |
| `app/Mail/NewAdminUserCreated.php` | 49 | Greeting |
| `app/Mail/SubscriptionCanceledNotification.php` | 50 | Greeting |
| `app/Notifications/NewProductCreated.php` | 32, 39 | Notification |
| `app/Notifications/ImportedProductsCreated.php` | 59 | Notification |
| `app/Http/Controllers/Api/JobNegotiationController.php` | 189, 445, 450 | **Line 189:** `profile?->first_name` (BUG – no such column) |
| `resources/views/admin/users/*.blade.php` | multiple | Display full_name |
| `resources/views/admin/admin-users/*.blade.php` | multiple | Display full_name |
| `resources/views/admin/subscriptions/*.blade.php` | multiple | Display full_name |
| `resources/views/admin/email-logs/show.blade.php` | 111 | Related user full_name |

### 1.3 `$contactUser->name` (User model – uses `users.name`)

| File | Line | Context |
|------|------|---------|
| `app/Http/Controllers/Api/RentalJobActionsController.php` | 124, 376, 487 | Default contact for supplier emails |
| `app/Http/Controllers/Api/RentalJobActionsController.php` | 645 | `$providerContact->name` – **BUG:** `$providerContact` is UserProfile, has `full_name` not `name` |

### 1.4 ContactSales / SupportRequest (Separate Tables)

- **`contact_sales`** – `name` column for lead form submissions (non-user)
- **`support-request`** – uses `$request->name` / `$profile->full_name` depending on auth

These are **out of scope** for user profile refactor unless you want to split lead names too.

---

## 2. Source of User Data in PSM

### 2.1 Table Structure

| Table | Name Column(s) | Purpose |
|-------|----------------|---------|
| `users` | `name` (nullable) | Legacy; rarely populated; some fallbacks use it |
| `user_profiles` | `full_name` (required) | **Primary** display name for users |

### 2.2 Where Full Name Comes From

- **Primary:** `user_profiles.full_name` – used in 90%+ of the codebase
- **Fallback:** `users.name` – used in a few places (RentalRequestController, UserResource, profile form)
- **Secondary fallback:** `users.username` – when profile or name is missing

### 2.3 Relationships

- `User` → `UserProfile` (hasOne)
- `Company` → `defaultContact` (User) → `profile` (UserProfile)
- `Company` → `defaultContactProfile` (UserProfile via `default_contact_id`)

---

## 3. Database Analysis

### 3.1 Current Schema

**users:**
```sql
name VARCHAR(255) NULLABLE
```

**user_profiles:**
```sql
full_name VARCHAR(255) NOT NULL
-- Index: idx_user_profiles_name_email (full_name, email)
```

### 3.2 Migration Plan

1. **Add columns to `user_profiles`:**
   - `first_name` VARCHAR(255) NULLABLE
   - `last_name` VARCHAR(255) NULLABLE

2. **Backfill script:** Split existing `full_name` into `first_name` and `last_name`:
   - First word → `first_name`
   - Rest → `last_name` (or empty if single word)

3. **Add accessor:** `getFullNameAttribute()` on UserProfile:
   ```php
   return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
   ```

4. **Optional:** Keep `full_name` column temporarily as a cached value, or drop it after migration. **Recommendation:** Keep for backward compatibility during transition, then deprecate.

5. **Index:** Consider adding index on `(first_name, last_name)` if search by name is common. Current index on `full_name` can be updated or replaced.

---

## 4. Critical Areas That Will Break

| Area | Risk | Mitigation |
|------|------|------------|
| **Sales Quote / Rental Request** | `user_name` in quoteRequest email | Use `$user->profile->full_name` (accessor) or `first_name + last_name` |
| **Rental Request creation** | `user_name` variable | Same |
| **Contact creation (CompanyUser)** | Profile creation with `full_name` | Accept `first_name` + `last_name` or `full_name` during transition |
| **Email sending** | All templates using `full_name`, `user_full_name`, `receiver_contact_name` | Accessor provides `full_name`; templates unchanged initially |
| **Admin user listing** | Tables show full_name | Add First Name / Last Name columns or keep single "Name" with accessor |
| **Authentication** | JWT / login don't depend on name | No change |
| **Activity logs** | If any log user name | Use accessor |
| **Notifications** | NewProductCreated, ImportedProductsCreated, etc. | Use accessor |
| **HubSpot integration** | Already splits full_name for firstname/lastname | Use `first_name`/`last_name` directly when available |
| **Flex integration** | No user name usage | No change |

---

## 5. Refactor Strategy

### Phase 1: Database & Model (Non-Breaking)

1. Create migration:
   ```php
   Schema::table('user_profiles', function (Blueprint $table) {
       $table->string('first_name', 255)->nullable()->after('profile_picture');
       $table->string('last_name', 255)->nullable()->after('first_name');
   });
   ```

2. Run migration.

3. Create data migration command to split `full_name`:
   ```php
   UserProfile::chunk(100, function ($profiles) {
       foreach ($profiles as $profile) {
           if ($profile->full_name && !$profile->first_name) {
               $parts = preg_split('/\s+/', trim($profile->full_name), 2);
               $profile->update([
                   'first_name' => $parts[0] ?? null,
                   'last_name' => $parts[1] ?? null,
               ]);
           }
       }
   });
   ```

4. Add `UserProfile` accessor:
   ```php
   public function getFullNameAttribute(): string
   {
       if (isset($this->attributes['full_name']) && $this->attributes['full_name']) {
           return $this->attributes['full_name'];
       }
       return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
   }
   ```
   Or use a mutator to keep `full_name` in sync when `first_name`/`last_name` change.

5. Add `first_name` and `last_name` to `UserProfile` fillable.

### Phase 2: Update Write Paths

6. **API: UserProfileController::updateName**
   - Accept `first_name` and `last_name` (or keep `name` and split server-side).
   - Update validation and persistence.

7. **API: CompanyUserController** (create/update user)
   - Accept `first_name` and `last_name` instead of/in addition to `name`.

8. **API: AuthController** (registration)
   - Accept `first_name` and `last_name`; build `full_name` for profile.

9. **Admin: UserManagementController, AdminUserManagementController**
   - Forms: replace single "Full Name" with "First Name" + "Last Name".
   - Validation and persistence.

10. **Web: ProfileController + update-profile-information-form**
    - Replace single name field with first_name + last_name.
    - ProfileUpdateRequest validation.

### Phase 3: Update Read Paths (Display)

11. **UserResource** – return `first_name`, `last_name`, and `full_name` (accessor).
12. **ProfileResource** – same.
13. **Admin views** – optionally show First Name / Last Name columns.
14. **RentalJobActionsController** – fix `$contactUser->name` → `$contactUser->profile?->full_name ?? 'there'`.
15. **RentalJobActionsController** – fix `$providerContact->name` → `$providerContact->full_name` (providerContact is UserProfile).
16. **JobNegotiationController** – fix `profile?->first_name` → `profile?->full_name` or use first_name when available.
17. **CompanyUserController::makeAdmin** – `$user->name` → `$user->profile?->full_name ?? $user->username`.

### Phase 4: Cleanup (Optional, Later)

18. Deprecate `users.name` – stop writing to it; eventually drop column.
19. Consider dropping `user_profiles.full_name` if fully replaced by accessor (requires all writes to use first/last).

---

## 6. Admin Panel Impact

| Page | Current | Change |
|------|---------|--------|
| **Users index** | `profile->full_name` | Keep "Name" column using accessor, or split into First / Last |
| **Users show** | `profile->full_name` | Same |
| **Users create** | Full Name field | First Name + Last Name fields |
| **Users edit** | Full Name field | First Name + Last Name fields |
| **Admin users index** | `profile->full_name` | Same |
| **Admin users create/edit** | Full Name field | First Name + Last Name fields |
| **Subscriptions index** | `user->profile->full_name` | Use accessor |
| **Subscriptions show** | Same | Same |
| **Email logs show** | `relatedUser->profile->full_name` | Use accessor |
| **Rental requests** | user_name in data | Use accessor |
| **Quotes** | user_name, provider_contact_name | Use accessor |

---

## 7. APIs Impacted

| Endpoint | Impact |
|----------|--------|
| `GET /user/profile` | Return `first_name`, `last_name`, `full_name` |
| `PATCH /profile/update-name` | Accept `first_name`, `last_name` |
| `POST /company/users` (create) | Accept `first_name`, `last_name` |
| `PATCH /company/users/{id}` (update) | Same |
| `POST /auth/register` | Accept `first_name`, `last_name` |
| `GET /user` (UserResource) | Return `first_name`, `last_name`, `full_name` |
| `GET /companies/*` (contact name) | Use accessor |
| `GET /subscriptions/*` | Use accessor |
| `GET /comments` | Use accessor |

---

## 8. Risk Areas

| Risk | Severity | Mitigation |
|------|----------|------------|
| Email templates expect `full_name` | Medium | Accessor ensures `full_name` still works |
| Frontend expects `name` or `full_name` | Medium | Add `first_name`/`last_name`; keep `full_name` in response |
| HubSpot expects first/last | Low | Already splitting; use native columns when available |
| Search by full_name (SubscriptionManagementController) | Medium | Search `first_name` OR `last_name` OR concatenated |
| ContactSales / SupportRequest | Low | Out of scope; separate tables |

---

## 9. Step-by-Step Implementation Plan

1. **Migration** – Add `first_name`, `last_name` to `user_profiles`.
2. **Backfill command** – Split existing `full_name` into first/last.
3. **UserProfile model** – Add fillable, accessor (and optionally mutator for full_name).
4. **Fix bugs first:**
   - UserProfileController line 180: `$user->name` → `$user->username`
   - UserProfileController line 161: `Rule::unique('users', 'name')` → `Rule::unique('users', 'username')`
   - RentalJobActionsController line 645: `$providerContact->name` → `$providerContact->full_name`
   - JobNegotiationController line 189: `profile?->first_name` → `profile?->full_name` (until first_name exists)
5. **Update API write paths** – CompanyUserController, AuthController, UserProfileController.
6. **Update Admin write paths** – UserManagementController, AdminUserManagementController.
7. **Update API read paths** – UserResource, ProfileResource, CompanyController, etc.
8. **Update Admin read paths** – Views (can keep single "Name" column with accessor initially).
9. **Update web profile** – ProfileController, ProfileUpdateRequest, update-profile-information-form.
10. **Update search** – SubscriptionManagementController `where('full_name', 'like', ...)` to search first_name + last_name.
11. **Test** – Registration, profile update, admin CRUD, emails, HubSpot, rental flows.
12. **Document** – API changelog, frontend integration notes.

---

## 10. Files That Must Be Updated

### Models
- `app/Models/UserProfile.php` – fillable, accessor

### Migrations
- New migration for `first_name`, `last_name`
- Optional: data migration command

### API Controllers
- `app/Http/Controllers/Api/UserProfileController.php`
- `app/Http/Controllers/Api/CompanyUserController.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Api/RentalRequestController.php`
- `app/Http/Controllers/Api/RentalJobActionsController.php`
- `app/Http/Controllers/Api/JobNegotiationController.php`
- `app/Http/Controllers/Api/SupportRequestController.php`
- `app/Http/Controllers/Api/ForgotPasswordController.php`
- `app/Http/Controllers/Api/ProductController.php`
- `app/Http/Controllers/Api/SubscriptionController.php`
- `app/Http/Controllers/Api/CommentController.php`
- `app/Http/Controllers/Api/CompanyController.php`

### Admin Controllers
- `app/Http/Controllers/Admin/UserManagementController.php`
- `app/Http/Controllers/Admin/AdminUserManagementController.php`
- `app/Http/Controllers/Admin/SubscriptionManagementController.php`

### Web Controllers
- `app/Http/Controllers/ProfileController.php`
- `app/Http/Controllers/Auth/RegisteredUserController.php`

### Requests
- `app/Http/Requests/ProfileUpdateRequest.php`

### Resources
- `app/Http/Resources/UserResource.php`
- `app/Http/Resources/ProfileResource.php`

### Views
- `resources/views/profile/partials/update-profile-information-form.blade.php`
- `resources/views/admin/users/index.blade.php`
- `resources/views/admin/users/show.blade.php`
- `resources/views/admin/users/create.blade.php`
- `resources/views/admin/users/edit.blade.php`
- `resources/views/admin/admin-users/index.blade.php`
- `resources/views/admin/admin-users/show.blade.php`
- `resources/views/admin/admin-users/create.blade.php`
- `resources/views/admin/admin-users/edit.blade.php`
- `resources/views/admin/subscriptions/index.blade.php`
- `resources/views/admin/subscriptions/show.blade.php`
- `resources/views/admin/email-logs/show.blade.php`

### Jobs / Mail / Notifications
- `app/Jobs/SyncUserToHubSpot.php` – use `first_name`/`last_name` directly when available
- `app/Mail/NewAdminUserCreated.php`
- `app/Mail/SubscriptionCanceledNotification.php`
- `app/Notifications/NewProductCreated.php`
- `app/Notifications/ImportedProductsCreated.php`

### Tests
- `tests/Feature/ProfileTest.php`

---

## 11. Safety Rules (Do NOT Break)

- **Existing APIs** – Keep `full_name` in responses during transition.
- **Auth system** – No dependency on name.
- **External integrations** – HubSpot already handles first/last; Flex does not use user name.
- **Email system** – Use accessor so templates keep receiving `full_name` until updated.
- **Backward compatibility** – Accept both `name`/`full_name` and `first_name`/`last_name` in APIs during transition.

---

## Appendix: Email Template Variables Using Name

| Template | Variables |
|----------|-----------|
| registrationSuccess | `name` |
| forgotPassword | `full_name` |
| support-request | `full_name` |
| contact-sales | `name` |
| product_created | `user_full_name` |
| imported_products | `user_full_name` |
| quoteRequest | `provider_contact_name`, `user_name` |
| rentalJobCancelled | `receiver_contact_name` |
| jobAutoCancelled | `receiver_contact_name` |
| new-admin-user | Uses `user` object (profile full_name) |
| subscriptionCanceled | Uses `user` object |

All of these can continue to receive a string (full name) from the accessor until templates are updated to use first/last if desired.
