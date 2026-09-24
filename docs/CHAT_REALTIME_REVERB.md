# Phase 2 – Real-Time Chat (Laravel Reverb)

**Scope:** WebSocket delivery, typing, and presence on top of the existing company-to-company REST chat.  
**Primary code paths:** `ChatService::sendMessage` → `ChatMessageSent` → Reverb; `routes/channels.php` → `ChatChannelAuthorizer`  
**Last reviewed against codebase:** September 2026

REST remains the source of truth for conversation history, message persistence, and unread/read state. Reverb only delivers live events.

```text
             Vue/Quasar
                 │
       ┌─────────┴─────────┐
       │                   │
    REST API          WebSocket
       │                   │
       ▼                   ▼
    Laravel             Reverb
       │                   │
       ▼                   │
     MySQL                 │
       │                   │
       └─────────┬─────────┘
                 ▼
          Real-time Chat UI
```

Do **not** assume cPanel/shared hosting can run a persistent WebSocket process. Reverb needs a long-running process (Supervisor, systemd, or equivalent).

---

## 1. Local

Run the Laravel API and the Reverb server together:

```bash
php artisan serve
php artisan reverb:start
```

Or use the project Composer script (includes Reverb):

```bash
composer run dev
```

Required `.env` (already described in `.env.example`):

```env
BROADCAST_CONNECTION=reverb

REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_APP_ID=psm-local
REVERB_APP_KEY=generate-a-random-key
REVERB_APP_SECRET=generate-a-random-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_ALLOWED_ORIGINS=*
```

Generate credentials (do not commit production secrets):

```bash
php -r "echo 'REVERB_APP_ID=psm-local'.PHP_EOL.'REVERB_APP_KEY='.bin2hex(random_bytes(16)).PHP_EOL.'REVERB_APP_SECRET='.bin2hex(random_bytes(16)).PHP_EOL;"
```

Local WebSocket URL: `ws://localhost:8080`

If Reverb is down, chat REST APIs still work. Incoming messages will not appear until the next REST fetch. `users.last_seen_at` / `POST /api/user/heartbeat` remains the Providers listing fallback.

---

## 2. Staging

| Item | Typical value |
| --- | --- |
| `.env` | `BROADCAST_CONNECTION=reverb` plus `REVERB_APP_*` |
| Reverb listen | `REVERB_SERVER_HOST=0.0.0.0`, `REVERB_SERVER_PORT=8080` |
| Laravel → Reverb publish | `REVERB_HOST` = staging Reverb hostname, `REVERB_PORT=443`, `REVERB_SCHEME=https` when TLS is terminated at the proxy |
| Frontend WebSocket | `wss://chat-staging.example.com` (or the same host with a `/reverb` path) |
| Origins | `REVERB_ALLOWED_ORIGINS=https://staging-frontend.example.com` |
| Process | Keep `php artisan reverb:start` running via Supervisor/systemd |

HTTPS pages **must** use WSS. Mixed content (`https` page + `ws://`) will fail in the browser.

Nginx example (TLS at the proxy, Reverb on 8080):

```nginx
location / {
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header Scheme $scheme;
    proxy_set_header SERVER_PORT $server_port;
    proxy_set_header REMOTE_ADDR $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_pass http://127.0.0.1:8080;
}
```

If the API and Reverb share one public host, put Reverb on a dedicated subdomain (recommended) or a dedicated path via `REVERB_SERVER_PATH`.

---

## 3. Production

Use a process manager. Supervisor example:

```ini
[program:psm-reverb]
process_name=%(program_name)s
command=php /path/to/psmadmin/artisan reverb:start --no-interaction
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/psmadmin/storage/logs/reverb.log
stopwaitsecs=10
```

systemd equivalent: `Type=simple`, `Restart=always`, same `artisan reverb:start` command.

Production `.env`:

```env
BROADCAST_CONNECTION=reverb
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_APP_ID=psm-production
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST=chat.example.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_ALLOWED_ORIGINS=https://app.example.com
```

Scale-out (multiple Reverb nodes) requires Redis and `REVERB_SCALING_ENABLED=true`. A single node is enough until connection volume requires it.

---

## 4. Channels and events

Echo prefixes:

| Laravel name | Wire name | Purpose |
| --- | --- | --- |
| `chat.conversation.{id}` | `presence-chat.conversation.{id}` | Messages, typing, who is in the thread |
| `chat.company.{id}` | `private-chat.company.{id}` | Inbox / unread for that company |
| `chat.online` | `presence-chat.online` | Which PSM users are connected |

Authorization uses the existing company-participant rules (`ChatConversation::hasCompanyParticipant`). Company C cannot join a Company A ↔ Company B conversation.

Events:

| Event class | Broadcast as | Payload |
| --- | --- | --- |
| `ChatMessageSent` | `chat.message.sent` | Phase 2 fields plus `sender_company_name`, `preview`, `rental_job_id`, `notification` (`title`, `body`, `sender_name`, `sender_company_name`, `conversation_id`, `message_id`) |
| `ChatMessageDeleted` | `chat.message.deleted` | `conversation_id`, `message_id`, `deleted_by_user_id`, `deleted_at`, `is_deleted` |
| `ChatMessagesRead` | `chat.messages.read` | `conversation_id`, `reader_user_id`, `reader_user_name`, `reader_company_id`, `last_read_at`, `unread_count` |
| `ChatUserTyping` | `chat.user.typing` | `conversation_id`, `user_id`, `user_name`, `is_typing` |

Typing is **not** stored in MySQL. The backend only broadcasts the latest `is_typing` state.

Phase 3 browser/desktop notifications use the `notification` object on `chat.message.sent`. See [CHAT_PHASE3.md](CHAT_PHASE3.md).

GET `/api/chat/realtime-config` (JWT) returns the public Reverb key, host, port, scheme, auth endpoint, channel names, event names, and the current user's browser-notification preference. It never returns `REVERB_APP_SECRET`.

Auth endpoint: `POST /api/broadcasting/auth` with `Authorization: Bearer {jwt}`.

---

## 5. Presence vs `last_seen_at`

| Mechanism | Meaning |
| --- | --- |
| Reverb presence (`presence-chat.online`) | Real-time current WebSocket connection |
| `users.last_seen_at` + `POST /api/user/heartbeat` | Persistent last activity / REST fallback |

Joining a presence channel also heartbeats `last_seen_at`. Do **not** remove the heartbeat API. Providers listing still uses `UserPresence::onlineCompanyIds()` from `last_seen_at`. A company is online when **any** of its users is online, not only the default contact.

Frontend derivation from presence members:

```text
Company A is online if any presence member has company_id === Company A
```

---

## 6. Logging

- Chat / unauthorized subscriptions / broadcast failures: `storage/logs/chat.log`
- Reverb process stdout: Supervisor/systemd log (or the terminal for local `reverb:start`)
- JWT tokens, passwords, and message bodies are not written to the chat log

If Reverb cannot start, check `REVERB_APP_KEY` / `REVERB_APP_SECRET` / port binds, then `php artisan reverb:start --debug`.

---

## 7. Hosting limitations

Reverb is a long-running PHP CLI process. It will **not** run on typical cPanel/shared PHP-FPM-only hosting. Staging and production need a VPS, container, or similar environment that can keep `php artisan reverb:start` alive and expose WSS.

If that infrastructure is not available, leave REST chat as-is and keep `BROADCAST_CONNECTION=null` or `log`. Do not replace Reverb with a polling workaround that changes the application architecture.

---

## 8. Frontend (Vue/Quasar) requirements

This repository is the Laravel API. Phase 2 frontend work is required in the Vue app:

1. Install `laravel-echo` and `pusher-js`.
2. Configure Echo with JWT (not cookies):

```js
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
window.Pusher = Pusher

const echo = new Echo({
  broadcaster: 'reverb',
  key: config.key,
  wsHost: config.host,
  wsPort: config.port,
  wssPort: config.port,
  forceTLS: config.scheme === 'https',
  enabledTransports: ['ws', 'wss'],
  authEndpoint: `${apiBaseUrl}/broadcasting/auth`,
  auth: {
    headers: {
      Authorization: `Bearer ${jwt}`,
      Accept: 'application/json',
    },
  },
})
```

3. **Message strategy (matches Phase 1 REST create):** append the sender’s message from the `201` REST response. Ignore the sender’s own `chat.message.sent` (Echo should send `X-Socket-ID` so Laravel uses `toOthers()`). Recipients append from the broadcast. Always dedupe by `message_id` / `id`.
4. Subscribe:
   - `Echo.join('chat.conversation.' + conversationId)` for the open thread
   - `Echo.private('chat.company.' + ownCompanyId)` for inbox / unread
   - `Echo.join('chat.online')` for company online status
5. Typing: debounce on the client (start → one `is_typing: true`; after idle → `is_typing: false`). Do **not** POST on every keystroke. Endpoint: `POST /api/chat/conversations/{id}/typing` `{ "is_typing": true|false }`.
6. Unread: REST remains source of truth (`GET /api/chat/conversations`, `GET /api/chat/unread-count`). On `chat.message.sent`, increment unread when `sender_company_id !== own company` and the thread is not the active view. On `chat.messages.read` for the current user (other device), set that conversation unread to 0. On reconnect, refetch conversations and unread-count.
7. Reconnection: Echo/Reverb reconnect automatically. On `echo.connector.pusher.connection` `connected` after a drop: rejoin channels, refetch recent messages, refetch unread. Dedupe by id so history + live events do not double-render.
8. Keep `POST /api/user/heartbeat` so Providers listing still works if Reverb is down.
9. Browser/desktop notifications: see [CHAT_PHASE3.md](CHAT_PHASE3.md). Do **not** call `Notification.requestPermission()` on app load. Show a browser notification only when the conversation is not the active view, `Notification.permission === 'granted'`, and `browser_notifications_enabled` is true. Click should open `/chat` (or equivalent) for `notification.conversation_id`.

`GET /api/chat/realtime-config` can supply Echo settings so host/port/key are not hard-coded.
