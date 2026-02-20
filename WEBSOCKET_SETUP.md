# Laravel WebSocket Setup

This project uses [Laravel Reverb](https://laravel.com/docs/reverb) for real-time WebSocket broadcasting. This guide walks you through the setup.

## Prerequisites

- PHP 8.2+
- Composer dependencies installed (`composer install`)
- Laravel Reverb is included via `laravel/reverb`

## 1. Environment Configuration

In your `.env` file, set:

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=my-app
REVERB_APP_KEY=my-key
REVERB_APP_SECRET=my-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

| Variable | Description |
|----------|-------------|
| `BROADCAST_CONNECTION` | Use `reverb` for WebSockets. Use `log` to test without a WebSocket server. |
| `REVERB_APP_ID` | App identifier (can be any string for local dev). |
| `REVERB_APP_KEY` | Public key for client connections. Must match client config. |
| `REVERB_APP_SECRET` | Secret for server-side API calls. |
| `REVERB_HOST` | WebSocket host (e.g. `localhost` or your domain). |
| `REVERB_PORT` | WebSocket port (default `8080`). |
| `REVERB_SCHEME` | `http` for local, `https` for production. |

## 2. Start the Reverb Server

Run Reverb in a separate terminal:

```bash
php artisan reverb:start
```

You should see output like:

```
INFO  Reverb server started on 0.0.0.0:8080
```

## 3. Queue Worker (Required for Broadcasting)

Events are broadcast via the queue. Ensure your queue worker is running:

```bash
php artisan queue:work
```

Use the same queue driver as in `.env` (e.g. `QUEUE_CONNECTION=database`). Run jobs in the background or use a process manager in production.

## 4. Client Setup (Laravel Echo)

Install dependencies:

```bash
npm install laravel-echo pusher-js
```

Example configuration (e.g. in a React/Vue/vanilla JS app):

```javascript
import Echo from "laravel-echo";
import Pusher from "pusher-js";
window.Pusher = Pusher;

const echo = new Echo({
    broadcaster: "reverb",
    key: import.meta.env.VITE_REVERB_APP_KEY,    // or process.env.REVERB_APP_KEY
    wsHost: import.meta.env.VITE_REVERB_HOST,    // or process.env.REVERB_HOST
    wsPort: import.meta.env.VITE_REVERB_PORT,    // or process.env.REVERB_PORT
    wssPort: import.meta.env.VITE_REVERB_PORT,
    forceTLS: import.meta.env.VITE_REVERB_SCHEME === "https",
    enabledTransports: ["ws", "wss"],
    authEndpoint: "/api/broadcasting/auth",
    auth: {
        headers: {
            Authorization: `Bearer ${yourJwtToken}`,
        },
    },
});

// Listen for private channel events
echo.private(`conversation.${conversationId}`)
    .listen(".message.sent", (payload) => {
        console.log("New message", payload.message);
    });
```

If using Vite, add to `.env` (and use `VITE_` prefix for client access):

```env
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

## 5. Channel Authorization

Private channels require auth. Send a POST request to `/api/broadcasting/auth`:

- **Headers:** `Authorization: Bearer <jwt_token>`
- **Body:** `channel_name=private-conversation.{id}`

Only authenticated users who are participants in the conversation can subscribe (see `routes/channels.php`).

## 6. Running Locally (Full Stack)

You can run all services with:

```bash
php artisan serve              # API (terminal 1)
php artisan reverb:start       # WebSocket (terminal 2)
php artisan queue:work         # Queue worker (terminal 3)
```

Or use `composer run dev` if configured to start these processes.

## 7. Disable WebSockets (Development Without Reverb)

To develop without starting Reverb, set:

```env
BROADCAST_CONNECTION=log
```

Events will be written to the log instead of broadcast. Useful when you don't need real-time features.

## 8. Production Notes

- Use `REVERB_SCHEME=https` and ensure TLS is configured.
- Run Reverb behind a reverse proxy (e.g. Nginx) if needed.
- Use a process manager (e.g. Supervisor) for Reverb and the queue worker.
- Keep `REVERB_APP_SECRET` secret and do not expose it to the client.

## Channels in This Project

| Channel | Type | Authorization |
|---------|------|---------------|
| `conversation.{id}` | Private | User must be a participant in the conversation |
| `App.Models.User.{id}` | Private | User ID must match authenticated user |

## Troubleshooting

| Issue | Solution |
|-------|----------|
| "Connection refused" | Ensure Reverb is running (`php artisan reverb:start`). |
| Events not received | Ensure queue worker is running (`php artisan queue:work`). |
| 403 on auth | Check JWT and that the user is allowed on the channel. |
| CORS errors | Verify `config/cors.php` and `HandleCors` middleware. |
