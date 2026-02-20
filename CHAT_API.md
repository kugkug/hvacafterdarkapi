# Chat API (Laravel Reverb)

Real-time chat API using Laravel Reverb for WebSocket broadcasting. All chat endpoints require JWT auth (`Authorization: Bearer <token>`).

## Setup

1. **Env** – In `.env` set:

    ```env
    BROADCAST_CONNECTION=reverb
    REVERB_APP_ID=my-app
    REVERB_APP_KEY=my-key
    REVERB_APP_SECRET=my-secret
    REVERB_HOST=localhost
    REVERB_PORT=8080
    REVERB_SCHEME=http
    ```

2. **Migrations**

    ```bash
    php artisan migrate
    ```

3. **Start Reverb** (in a separate terminal)

    ```bash
    php artisan reverb:start
    ```

4. **Queue worker** (for broadcasting, if using `QUEUE_CONNECTION=database`)
    ```bash
    php artisan queue:work
    ```

## REST Endpoints

### Categories

| Method | Endpoint                    | Description                          |
| ------ | --------------------------- | ------------------------------------ |
| GET    | `/api/v1/categories`        | List all categories (any user)       |
| GET    | `/api/v1/categories/{id}`   | Get one category                     |
| POST   | `/api/v1/categories`        | Create category (admin only)         |
| PUT    | `/api/v1/categories/{id}`   | Update category (admin only)         |
| DELETE | `/api/v1/categories/{id}`   | Delete category (admin only)         |

**Create category body:** `name` (required), `slug` (optional, auto from name), `description` (optional).

**Admin role:** Set `users.role = 'admin'` in the database for admin users.

### Conversations

| Method | Endpoint                                           | Description                                             |
| ------ | -------------------------------------------------- | ------------------------------------------------------- |
| GET    | `/api/v1/conversations`                            | List current user's conversations                       |
| POST   | `/api/v1/conversations`                            | Create room or direct chat (see below)                  |
| GET    | `/api/v1/conversations/{id}`                       | Get one conversation                                    |
| POST   | `/api/v1/conversations/{id}/close`                 | Close room; creator only, group rooms only              |
| POST   | `/api/v1/conversations/{id}/invite`                | Invite users to room (body: `user_ids[]`); creator only |
| DELETE | `/api/v1/conversations/{id}/participants/{userId}` | Remove user from room; creator only                     |
| POST   | `/api/v1/conversations/{id}/ban/{userId}`          | Ban user (removes + blocks re-invite); creator only     |
| POST   | `/api/v1/conversations/{id}/read`                  | Mark conversation as read                               |
| GET    | `/api/v1/conversations/{id}/messages?per_page=20`  | Paginated messages                                      |
| POST   | `/api/v1/conversations/{id}/messages`              | Send message (body: `body`)                             |

### Open / create a chat room

- **Group room:** `POST /api/v1/conversations` with `category_id` (required), `type: "group"`, optional `name`, optional `user_ids[]` (empty = room with just you, others can be invited).
- **Direct chat:** `POST /api/v1/conversations` with `user_ids: [otherUserId]` (or `type: "direct"`). `category_id` is not used for direct chats.

### Close room

- **Close:** Creator can close a group room. Closed rooms: no new messages, no new invites. History remains readable.

### Invite / remove / ban

- **Invite:** Creator posts `user_ids[]`; banned users and closed rooms are rejected.
- **Remove:** Creator removes a participant (they can be re-invited).
- **Ban:** Creator bans and removes; banned users cannot be re-invited.

## Real-time (Reverb)

New messages are broadcast on the private channel `private-conversation.{id}` as the event `message.sent`.

**Channel auth:** `POST /api/broadcasting/auth` with the same JWT. Send channel name as `private-conversation.{id}`.

**Laravel Echo (client) example:**

```javascript
import Echo from "laravel-echo";
import Pusher from "pusher-js";
window.Pusher = Pusher;

const echo = new Echo({
    broadcaster: "reverb",
    key: process.env.REVERB_APP_KEY,
    wsHost: process.env.REVERB_HOST,
    wsPort: process.env.REVERB_PORT,
    wssPort: process.env.REVERB_PORT,
    forceTLS: process.env.REVERB_SCHEME === "https",
    enabledTransports: ["ws", "wss"],
    authEndpoint: "/api/broadcasting/auth",
    auth: {
        headers: {
            Authorization: `Bearer ${yourJwtToken}`,
        },
    },
});

echo.private(`conversation.${conversationId}`).listen(".message.sent", (e) => {
    console.log("New message", e.message);
});
```

## Throttling

Sending messages is limited to 60 per minute per user.
