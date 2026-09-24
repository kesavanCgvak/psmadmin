# Phase 3 – Chat notifications, message management, and conversation state

**Scope:** Production-ready additions on top of Phase 1 (REST company-to-company chat) and Phase 2 (Reverb).  
**Does not change:** Company A ↔ Company B conversations, per-user `last_read_at`, `users.last_seen_at`, JWT auth, or Reverb as the WebSocket server.  
**Last reviewed against codebase:** September 2026

REST remains the source of truth. Reverb delivers live events. Typing is still not persisted.

---

## 1. Database

Migration: `database/migrations/2026_09_17_100000_add_phase3_chat_features.php`

Run on staging, then production (same as other Laravel migrations):

```bash
php artisan migrate
```

| Change | Purpose |
| --- | --- |
| `chat_conversation_user_states.archived_at` | Per-user archive/hide. Null = visible in the active list. |
| Indexes on `(user_id, archived_at)` and `(conversation_id, last_read_at)` | Archive list + unread lookups |
| `chat_messages.delivered_at` | Delivery timestamp (recipient company online at send, or recipient later opens the thread) |
| `chat_messages.deleted_by_user_id` | Audit: who soft-deleted the row |
| Index `(conversation_id, sender_company_id, created_at)` | Unread counts |
| FULLTEXT on `chat_messages.message` (MySQL only) | Message search |
| `chat_user_settings` | Per-user `browser_notifications_enabled` (default `false`) |

Soft deletes on `chat_messages` already existed (`deleted_at`). Phase 3 uses them; rows are never physically removed.

No new environment variables. Reverb / queue settings are unchanged from Phase 2.

---

## 2. API

All routes stay under `jwt.verify` and `/api/chat`. Existing Phase 1/2 endpoints and JSON fields remain. New fields are additive.

### Existing (unchanged paths)

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/api/chat/conversations` | New query: `archived`, `include_archived`, `general_only`, `rental_job_id`. New item fields below. `meta.total_unread` added. |
| POST | `/api/chat/conversations` | Optional `rental_job_id` creates/returns the job-specific thread. Omit it for the general company pair (`rental_job_id = null`). |
| GET | `/api/chat/conversations/{id}/messages` | Includes soft-deleted placeholders. New message fields below. |
| POST | `/api/chat/conversations/{id}/messages` | Same body. Response includes new message fields. |
| POST | `/api/chat/conversations/{id}/read` | Still per **user**. Broadcasts `chat.messages.read`. |
| POST | `/api/chat/conversations/{id}/typing` | Unchanged |
| GET | `/api/chat/realtime-config` | Adds events + `browser_notifications` |

### New

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/chat/conversations/{id}` | Single conversation metadata (notification click / reconnect) |
| DELETE | `/api/chat/conversations/{id}/messages/{messageId}` | Sender soft-deletes own message |
| POST | `/api/chat/conversations/{id}/archive` | Hide from **this user's** active list |
| POST | `/api/chat/conversations/{id}/unarchive` | Restore for **this user** |
| GET | `/api/chat/search?q=` | Conversations + messages the user is allowed to see (`q` min 2, max 100 chars) |
| GET | `/api/chat/unread-count` | `{ total_unread, conversations_with_unread }` |
| GET | `/api/chat/notification-settings` | `{ browser_notifications_enabled }` |
| PUT | `/api/chat/notification-settings` | Body: `{ "browser_notifications_enabled": true\|false }` |

Search is throttled (30/min). Message send/delete remain throttled (60/min).

### Conversation item (additive fields)

Existing: `id`, `other_company_*`, `online_status`, `last_message`, `last_message_at`, `unread_count`, `created_at`.

Added:

- `archived` / `archived_at` — current user only
- `rental_job_id` / `rental_job` `{ id, name }` or `null`
- `last_message.sender_company_name`
- `last_message.is_deleted`
- `meta.total_unread` on the list

### Message item (additive fields)

Existing: `id`, `conversation_id`, `sender_user_id`, `sender_user_name`, `sender_company_id`, `message`, `message_type`, `created_at`, `is_mine`.

Added:

- `sender_company_name`
- `is_deleted`, `deleted_at` — when true, `message` is `""` (original text stays in MySQL only)
- `delivered_at`
- `status` — `sent` \| `delivered` \| `read` (sender view: `read` if **any** recipient-company user has read)
- `read_by` — `[{ user_id, user_name, read_at }]` for **recipient-company users who have actually read**. User 1 reading does **not** put User 2 in this list.

---

## 3. Unread, read receipts, archive

Unread is still **per user**, not per company:

- Unread = non-deleted messages from the **other company** with `created_at > last_read_at` (or no `last_read_at`).
- User A marking read does not clear User B (same company).
- `GET /api/chat/unread-count` is the reconnect/page-refresh source of truth for the badge.
- After a live `chat.message.sent`, increment locally only if the sender is the other company and the thread is not open, then reconcile with unread-count on reconnect.

Read receipts:

- Computed from each recipient-company user's `last_read_at`.
- Broadcast `chat.messages.read` with `reader_user_id` so senders can add that user to `read_by` without implying teammates have read.

Archive:

- Per user via `archived_at`.
- User A archive ≠ User B archive.
- A message from the **other company** clears `archived_at` for users in the recipient company (thread reappears).
- A teammate sending does **not** unarchive another user on the same side.
- Conversations are never deleted when archived.

---

## 4. Browser / desktop notifications

Mobile push is **not** implemented. There is no FCM/APNs integration.

Backend:

- Every `chat.message.sent` payload includes `notification`: sender name, sender company name, short preview, `conversation_id`, `message_id`.
- It does **not** include email, phone, JWT, or recipient user ids.
- `PUT /api/chat/notification-settings` stores the user preference. Default is **off**.
- The API never prompts for browser permission.

Frontend (Vue/Quasar) requirements:

1. Do **not** call `Notification.requestPermission()` on load or on every message.
2. Provide a user control (e.g. Chat settings) that:
   - `PUT /api/chat/notification-settings` `{ browser_notifications_enabled: true }`
   - then, only from that click, call `Notification.requestPermission()` once if `permission === 'default'`.
3. If the user denies permission, keep the preference UI available; do not retry automatically.
4. Show a `new Notification(title, { body, tag: 'chat-'+conversationId })` only when **all** of:
   - `Notification.permission === 'granted'`
   - `browser_notifications_enabled === true` (from settings or realtime-config)
   - the conversation is **not** the currently open thread
   - `sender_company_id` is not the current user's company (optional extra guard)
5. `notificationclick` should focus the app and open that `conversation_id` (`GET /api/chat/conversations/{id}` + messages).
6. If the thread is open, skip the desktop notification; still rely on the live message event. Call `POST .../read` as today.

---

## 5. Search and rental jobs

Search (`GET /api/chat/search?q=`):

- Only conversations where the user's company is `company_a` or `company_b`.
- Matches other company name, message text, and sender name (`full_name` / username).
- Soft-deleted messages are excluded.
- Results are paginated (`per_page` max 50). Company C cannot search into A ↔ B.

Rental jobs:

- General chat: `rental_job_id = null` (Phase 1 default). `POST /conversations` without `rental_job_id` still creates/reuses this thread.
- Job chat: `POST /conversations` with `rental_job_id` uses a distinct `pair_key` (`min:max:job:{id}`).
- Filter: `GET /conversations?rental_job_id={id}` or `?general_only=1`.
- Do not assume every conversation is job-specific.

---

## 6. Broadcasting

Same channels as Phase 2 (`chat.conversation.{id}`, `chat.company.{id}`, `chat.online`). Same JWT auth endpoint.

| Event | When |
| --- | --- |
| `chat.message.sent` | After the message row is stored |
| `chat.message.deleted` | After soft-delete |
| `chat.messages.read` | After this user marks the conversation read |
| `chat.user.typing` | Unchanged; not stored |

Failed broadcasts are rescued and written to `storage/logs/chat.log` (no message bodies / tokens).

---

## 7. Authorization

Unchanged rule: the user's `company_id` must be `company_a_id` or `company_b_id`.

Additionally:

- Delete: sender only, and only inside an authorized conversation.
- Archive / unarchive / read / search / show: company participant.
- Channel auth is unchanged (`ChatChannelAuthorizer`).

---

## 8. Staging / production

Same as Phase 2:

1. Deploy code, run `php artisan migrate` on staging, then production.
2. Keep `php artisan reverb:start` under Supervisor/systemd.
3. No new queue worker is required for chat events (`ShouldBroadcastNow`).
4. No new `.env` keys.
5. HTTPS pages must use WSS.

If Reverb is down, REST chat (history, unread, archive, delete, search) still works; live events and live desktop notifications will not until reconnect + refetch.

---

## 9. Frontend checklist (beyond Phase 2)

- Render `is_deleted` placeholders; do not show empty bubbles as normal text.
- Use `read_by` (not company-wide ticks) for receipts.
- Active list: default `GET /conversations` (archived excluded). Archived view: `?archived=1`.
- Badge: `meta.total_unread` or `GET /unread-count`; correct after user switch (JWT is per user).
- Search UI against `GET /chat/search`.
- Optional job filter via `rental_job_id`.
- Notification permission UX as in section 4.
