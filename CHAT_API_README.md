# Conversation & Message API Documentation

A detailed guide for using the chat API: conversations (rooms, direct chats), messages, and real-time events via Laravel Reverb.

---

## Table of Contents

- [Overview](#overview)
- [Authentication](#authentication)
- [Base URL & Headers](#base-url--headers)
- [Conversation API](#conversation-api)
- [Message API](#message-api)
- [Error Responses](#error-responses)
- [Real-Time (WebSocket)](#real-time-websocket)
- [Throttling & Limits](#throttling--limits)
- [Setup](#setup)

---

## Overview

The API supports:

- **Direct chats** – 1-on-1 conversations
- **Group rooms** – multi-user chat rooms with a creator
- **Room management** – open, close, invite, remove, ban
- **Messages** – send and retrieve messages with pagination
- **Real-time** – new messages via Laravel Reverb WebSockets

All conversation and message endpoints require JWT authentication.

---

## Authentication

Use a JWT token in the `Authorization` header:

```
Authorization: Bearer YOUR_JWT_TOKEN
```

Obtain a token via `POST /api/v1/user/login`:

```bash
curl -X POST https://your-domain.com/api/v1/user/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"your-password"}'
```

---

## Base URL & Headers

- **Base URL:** `https://your-domain.com/api/v1` (or `http://localhost/api/v1` locally)
- **Content-Type:** `application/json`

All examples assume the `Authorization` header is set.

---

## Conversation API

### 1. List Conversations

Returns all conversations for the authenticated user.

| Method | Endpoint |
|--------|----------|
| GET | `/conversations` |

**Example Request:**

```bash
curl -X GET https://your-domain.com/api/v1/conversations \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Example Response (200 OK):**

```json
{
  "status": true,
  "data": [
    {
      "id": 1,
      "type": "group",
      "name": "Team Chat",
      "created_by": { "id": 2, "name": "John Doe" },
      "is_creator": false,
      "is_closed": false,
      "closed_at": null,
      "participants": [
        { "id": 2, "name": "John Doe" },
        { "id": 3, "name": "Jane Smith" }
      ],
      "last_message": {
        "id": 15,
        "body": "See you tomorrow!",
        "created_at": "2026-02-19T14:30:00.000000Z",
        "user": { "id": 3, "name": "Jane Smith" }
      },
      "unread_count": 2,
      "updated_at": "2026-02-19T14:30:00.000000Z"
    }
  ]
}
```

---

### 2. Create Conversation

Creates a direct chat or a group room.

| Method | Endpoint |
|--------|----------|
| POST | `/conversations` |

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| type | string | No | `direct` or `group`. Defaults by participant count |
| name | string | No | Room name (group only) |
| user_ids | array | No* | Array of user IDs. Required for direct; optional for group |

*For direct chat, `user_ids` must contain exactly one other user. For group, it can be empty (room with only you) or include others.

**Create a direct chat (1-on-1):**

```bash
curl -X POST https://your-domain.com/api/v1/conversations \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"user_ids": [3]}'
```

**Create a group room (empty, others invited later):**

```bash
curl -X POST https://your-domain.com/api/v1/conversations \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type": "group", "name": "Project Alpha", "user_ids": []}'
```

**Create a group room with initial participants:**

```bash
curl -X POST https://your-domain.com/api/v1/conversations \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type": "group", "name": "Project Alpha", "user_ids": [3, 4, 5]}'
```

**Example Response (201 Created):**

```json
{
  "status": true,
  "message": "Conversation created.",
  "data": {
    "id": 2,
    "type": "group",
    "name": "Project Alpha",
    "created_by": { "id": 2, "name": "John Doe" },
    "is_creator": true,
    "is_closed": false,
    "closed_at": null,
    "participants": [
      { "id": 2, "name": "John Doe" },
      { "id": 3, "name": "Jane Smith" }
    ]
  }
}
```

---

### 3. Get Single Conversation

| Method | Endpoint |
|--------|----------|
| GET | `/conversations/{id}` |

**Example Request:**

```bash
curl -X GET https://your-domain.com/api/v1/conversations/1 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Example Response (200 OK):**

```json
{
  "status": true,
  "data": {
    "id": 1,
    "type": "group",
    "name": "Team Chat",
    "created_by": { "id": 2, "name": "John Doe" },
    "is_creator": false,
    "is_closed": false,
    "closed_at": null,
    "participants": [
      { "id": 2, "name": "John Doe" },
      { "id": 3, "name": "Jane Smith" }
    ]
  }
}
```

---

### 4. Close Room

Closes a group room. Creator only. Closed rooms: no new messages, no new invites. History remains readable.

| Method | Endpoint |
|--------|----------|
| POST | `/conversations/{id}/close` |

**Example Request:**

```bash
curl -X POST https://your-domain.com/api/v1/conversations/1/close \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Example Response (200 OK):**

```json
{
  "status": true,
  "message": "Room closed.",
  "data": {
    "id": 1,
    "type": "group",
    "name": "Team Chat",
    "created_by": { "id": 2, "name": "John Doe" },
    "is_creator": true,
    "is_closed": true,
    "closed_at": "2026-02-19T15:00:00.000000Z",
    "participants": [...]
  }
}
```

---

### 5. Invite Users to Room

Add users to a group room. Creator only. Not allowed for direct chats or closed rooms.

| Method | Endpoint |
|--------|----------|
| POST | `/conversations/{id}/invite` |

**Request Body:**

```json
{
  "user_ids": [4, 5, 6]
}
```

**Example Request:**

```bash
curl -X POST https://your-domain.com/api/v1/conversations/1/invite \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"user_ids": [4, 5, 6]}'
```

**Example Response (200 OK):**

```json
{
  "status": true,
  "message": "Users invited.",
  "data": {
    "id": 1,
    "type": "group",
    "name": "Team Chat",
    "participants": [...]
  }
}
```

---

### 6. Remove User from Room

Removes a participant. Creator only. The user can be re-invited later.

| Method | Endpoint |
|--------|----------|
| DELETE | `/conversations/{id}/participants/{userId}` |

**Example Request:**

```bash
curl -X DELETE https://your-domain.com/api/v1/conversations/1/participants/5 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Example Response (200 OK):**

```json
{
  "status": true,
  "message": "User removed.",
  "data": {
    "id": 1,
    "type": "group",
    "name": "Team Chat",
    "participants": [...]
  }
}
```

---

### 7. Ban User from Room

Bans and removes a user. Creator only. Banned users cannot be re-invited.

| Method | Endpoint |
|--------|----------|
| POST | `/conversations/{id}/ban/{userId}` |

**Example Request:**

```bash
curl -X POST https://your-domain.com/api/v1/conversations/1/ban/5 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Example Response (200 OK):**

```json
{
  "status": true,
  "message": "User banned.",
  "data": {
    "id": 1,
    "type": "group",
    "name": "Team Chat",
    "participants": [...]
  }
}
```

---

### 8. Mark Conversation as Read

Updates your last-read timestamp for unread counts.

| Method | Endpoint |
|--------|----------|
| POST | `/conversations/{id}/read` |

**Example Request:**

```bash
curl -X POST https://your-domain.com/api/v1/conversations/1/read \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Example Response (200 OK):**

```json
{
  "status": true,
  "message": "Marked as read."
}
```

---

## Message API

### 1. List Messages

Returns paginated messages for a conversation.

| Method | Endpoint |
|--------|----------|
| GET | `/conversations/{id}/messages` |

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| per_page | int | 20 | Messages per page (1–100) |
| page | int | 1 | Page number |

**Example Request:**

```bash
curl -X GET "https://your-domain.com/api/v1/conversations/1/messages?per_page=20&page=1" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Example Response (200 OK):**

```json
{
  "status": true,
  "data": [
    {
      "id": 15,
      "conversation_id": 1,
      "user_id": 3,
      "body": "See you tomorrow!",
      "created_at": "2026-02-19T14:30:00.000000Z",
      "user": { "id": 3, "name": "Jane Smith" }
    },
    {
      "id": 14,
      "conversation_id": 1,
      "user_id": 2,
      "body": "Sounds good.",
      "created_at": "2026-02-19T14:25:00.000000Z",
      "user": { "id": 2, "name": "John Doe" }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 20,
    "total": 45
  }
}
```

Messages are ordered newest first.

---

### 2. Send Message

Sends a message to a conversation. You must be a participant and the room must not be closed.

| Method | Endpoint |
|--------|----------|
| POST | `/conversations/{id}/messages` |

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| body | string | Yes | Message text (max 65535 characters) |

**Example Request:**

```bash
curl -X POST https://your-domain.com/api/v1/conversations/1/messages \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"body": "Hello, how is everyone?"}'
```

**Example Response (201 Created):**

```json
{
  "status": true,
  "message": "Message sent.",
  "data": {
    "id": 16,
    "conversation_id": 1,
    "user_id": 2,
    "body": "Hello, how is everyone?",
    "created_at": "2026-02-19T15:00:00.000000Z",
    "user": { "id": 2, "name": "John Doe" }
  }
}
```

New messages are broadcast in real time to other participants via Laravel Reverb.

---

## Error Responses

Standard error format:

```json
{
  "status": false,
  "message": "Error description."
}
```

Common HTTP status codes:

| Code | Meaning |
|------|---------|
| 404 | Conversation or resource not found, or you are not a participant |
| 403 | Forbidden (e.g. not the room creator) |
| 422 | Validation error or invalid action (e.g. closed room, banned user) |
| 429 | Too many requests (throttled) |

**Example (404):**

```json
{
  "status": false,
  "message": "Conversation not found."
}
```

**Example (422 – closed room):**

```json
{
  "status": false,
  "message": "Cannot send messages to a closed room."
}
```

---

## Real-Time (WebSocket)

New messages are broadcast over Laravel Reverb on private channels.

**Channel:** `private-conversation.{id}`  
**Event:** `message.sent`

**Payload structure:**

```json
{
  "message": {
    "id": 16,
    "conversation_id": 1,
    "user_id": 2,
    "body": "Hello!",
    "created_at": "2026-02-19T15:00:00.000000Z",
    "user": { "id": 2, "name": "John Doe" }
  }
}
```

### Channel authentication

`POST /api/broadcasting/auth` with the same JWT. The channel name must be `private-conversation.{id}`.

### Laravel Echo (JavaScript)

```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
window.Pusher = Pusher;

const echo = new Echo({
  broadcaster: 'reverb',
  key: process.env.REVERB_APP_KEY,
  wsHost: process.env.REVERB_HOST,
  wsPort: process.env.REVERB_PORT,
  wssPort: process.env.REVERB_PORT,
  forceTLS: process.env.REVERB_SCHEME === 'https',
  enabledTransports: ['ws', 'wss'],
  authEndpoint: '/api/broadcasting/auth',
  auth: {
    headers: {
      Authorization: `Bearer ${yourJwtToken}`,
    },
  },
});

echo.private(`conversation.${conversationId}`).listen('.message.sent', (e) => {
  console.log('New message:', e.message);
});
```

---

## Throttling & Limits

| Action | Limit |
|--------|-------|
| Send message | 60 requests per minute per user |

Exceeding the limit returns `429 Too Many Requests`.

---

## Setup

### Environment (.env)

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=my-app
REVERB_APP_KEY=my-key
REVERB_APP_SECRET=my-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

### Commands

```bash
# Run migrations
php artisan migrate

# Start Reverb (WebSocket server)
php artisan reverb:start

# Start queue worker (for broadcast jobs)
php artisan queue:work
```

---

## Quick Reference

| Action | Method | Endpoint |
|--------|--------|----------|
| List conversations | GET | `/api/v1/conversations` |
| Create conversation | POST | `/api/v1/conversations` |
| Get conversation | GET | `/api/v1/conversations/{id}` |
| Close room | POST | `/api/v1/conversations/{id}/close` |
| Invite users | POST | `/api/v1/conversations/{id}/invite` |
| Remove user | DELETE | `/api/v1/conversations/{id}/participants/{userId}` |
| Ban user | POST | `/api/v1/conversations/{id}/ban/{userId}` |
| Mark as read | POST | `/api/v1/conversations/{id}/read` |
| List messages | GET | `/api/v1/conversations/{id}/messages` |
| Send message | POST | `/api/v1/conversations/{id}/messages` |
