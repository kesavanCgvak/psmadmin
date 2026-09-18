# Phase 4 – Chat notifications (email, SMS, browser)

**Scope:** Per-user offline email/SMS plus the existing Phase 3 browser-notification payload.  
**Does not change:** Company A ↔ Company B conversations, REST as source of truth, Reverb for live delivery, per-user unread/read, or Twilio as the SMS provider.  
**Last reviewed against codebase:** September 2026

Notifications are a **secondary** delivery path. Reverb still delivers to connected users. A failed email/SMS never rolls back a saved chat message.

```text
User sends message
        ↓
Save message (REST)
        ↓
Broadcast chat.message.sent (Reverb)
        ↓
Queue ProcessChatMessageNotificationsJob
        ↓
ChatNotificationService (per recipient user)
        ↓
Online  → no offline email/SMS (browser remains frontend)
Offline → email if enabled; SMS if enabled + consented + valid mobile
```

---

## 1. Database

Migration: `database/migrations/2026_09_18_100000_add_phase4_chat_notifications.php`

```bash
php artisan migrate
```

| Change | Purpose |
| --- | --- |
| `chat_user_settings.email_notifications_enabled` | Per-user offline email (default **true**, transactional) |
| `chat_user_settings.sms_notifications_enabled` | Per-user offline SMS (default **false**, opt-in) |
| `chat_user_settings.sms_consented_at` | Set when the user first enables SMS |
| `chat_notification_logs` | Dedup + audit: user + message + channel |

`chat_notification_logs` unique key: `(user_id, message_id, channel)`. Statuses: `pending`, `sent`, `skipped`, `throttled`, `failed`.

No second SMS provider. No Laravel `notifications` table duplication.

---

## 2. Recipients

Recipients are **users on the other company**, loaded on the backend from `users.company_id`. Frontend-supplied recipient IDs are ignored.

- User A at Company A → Company B: User 1 and User 2 are evaluated independently.
- The default company contact is **not** the only recipient.
- The sender is never notified. Teammates of the sender are never notified.
- Blocked users are skipped.

---

## 3. Online vs offline

Reverb presence is not queryable from PHP on a single Reverb node. Joining `presence-chat.online` / `presence-chat.conversation.{id}` already heartbeats `users.last_seen_at` (`ChatChannelAuthorizer`). `POST /api/user/heartbeat` keeps it fresh.

A recipient is **online** when `last_seen_at` is within `ONLINE_STATUS_TIMEOUT` (see `UserPresence::onlineUserIds()`). Otherwise they are offline.

| State | WebSocket | Browser notification | Offline email | Offline SMS |
| --- | --- | --- | --- | --- |
| Online | Reverb `chat.message.sent` | Frontend, if permitted + preference + thread not open | No | No |
| Offline | No active connection | N/A | If email enabled + valid email | If SMS enabled, `sms_consented_at` set, valid mobile, Twilio configured |

---

## 4. Browser notifications

Unchanged from Phase 3, with an additive `notification.tag` (`chat-{conversationId}`) for client-side dedupe.

Backend does **not** call the browser Notification API and does **not** send FCM/APNs.

`GET /api/chat/realtime-config` now also returns `email_notifications` and `sms_notifications`.

---

## 5. Email

Queued via `ProcessChatMessageNotificationsJob` → `SendChatEmailNotificationJob` (3 tries, 60s backoff). Template: `resources/views/emails/chat_message_received.blade.php` (same PSM header/footer as other mail).

The message API does not wait for SMTP. `Mail::send` runs inside the queued job.

Conversation link: `{APP_FRONTEND_URL}/{CHAT_CONVERSATION_PATH}` with `{id}` replaced. Default path: `#/chat?conversation={id}`.

---

## 6. SMS

Optional. Reuses `SmsProvider` (Twilio by default) and `SmsLogger` / `sms_logs`. Enabling SMS in settings records consent (`sms_consented_at`). SMS is never force-enabled.

Body does **not** include the chat text:

```text
PSM: You have a new message from John at ABC Rentals. Open Pro Subrental Marketplace to reply.
```

Invalid/missing mobile, disabled preference, missing consent, or unconfigured Twilio → skip. Twilio number-level STOP/HELP is unchanged; this app does not add a second opt-out webhook.

---

## 7. Preferences API

Existing routes, additive fields:

| Method | Path |
| --- | --- |
| GET | `/api/chat/notification-settings` |
| PUT | `/api/chat/notification-settings` |

```json
{
  "browser_notifications_enabled": false,
  "email_notifications_enabled": true,
  "sms_notifications_enabled": false,
  "sms_consented": false
}
```

PUT accepts any subset of the three booleans (at least one required). Phase 3 clients that only send `browser_notifications_enabled` still work.

Defaults: browser **off**, email **on** (transactional, like job-offer mail), SMS **off**.

---

## 8. Anti-spam and dedupe

- Identity: `(user_id, message_id, channel)` unique log row. Queue retries reuse the same row.
- Throttle: if an email/SMS was **sent** to the same user for the same conversation within `CHAT_NOTIFICATION_THROTTLE_SECONDS` (default 300), further messages are `throttled` rather than sent. Real-time delivery is not delayed.
- Jobs implement `ShouldBeUnique` on the log/message id.

---

## 9. Environment

Existing: `APP_FRONTEND_URL`, `QUEUE_CONNECTION`, `MAIL_*`, `TWILIO_*`, `SMS_DRIVER`, `ONLINE_STATUS_TIMEOUT`, Reverb keys.

New optional:

```env
CHAT_NOTIFICATION_THROTTLE_SECONDS=300
CHAT_CONVERSATION_PATH="#/chat?conversation={id}"
```

Workers: the app already uses `QUEUE_CONNECTION=database`. Keep `php artisan queue:work` running so offline email/SMS leave the HTTP request. Reverb remains a separate process.

---

## 10. Staging / production

1. Deploy code; run `php artisan migrate`.
2. Confirm `APP_FRONTEND_URL` points at the Vue app (chat deep links).
3. Queue worker must be running (`composer run dev` includes `queue:listen` locally).
4. Twilio credentials unchanged; SMS stays off until each user enables it.
5. If Reverb is down, REST chat still works; live browser notifications will not; offline email/SMS still run from `last_seen_at`.

---

## 11. Frontend checklist

- Settings UI: browser (existing), email when offline, SMS when offline (unchecked by default).
- `PUT /api/chat/notification-settings` may send any of the three flags.
- Browser Notification API rules from [CHAT_PHASE3.md](CHAT_PHASE3.md) still apply. Use `notification.tag` to replace prior notifications for the same conversation.
- Email “Open Conversation” lands on `#/chat?conversation={id}` unless `CHAT_CONVERSATION_PATH` is changed to match the Vue router.
