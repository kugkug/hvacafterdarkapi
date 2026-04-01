<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Events\MessageSent;
use App\Events\PrivateMessageReceived;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MessageController extends Controller
{
    public function index(Request $request, int $conversationId): JsonResponse
    {
        $conversation = Conversation::with(['users:id,name,email', 'creator:id,name', 'category:id,name,slug'])
            ->find($conversationId);

        if (! $conversation || ! $conversation->hasParticipant($request->user())) {
            return response()->json([
                'status' => false,
                'message' => 'Conversation not found.',
            ], 404);
        }

        $perPage = (int) $request->get('per_page', 200);
        $perPage = min(max($perPage, 1), 100);

        $messages = $conversation->messages()
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->paginate($perPage);


        $messages->getCollection()->transform(function (Message $m) {
            return [
                'id' => $m->id,
                'conversation_id' => $m->conversation_id,
                'user_id' => $m->user_id,
                'body' => $m->body,
                'created_at' => $m->created_at->toIso8601String(),
                'created_time' => $m->created_at->format('H:i A'),
                'created_date' => $m->created_at->format('Y-m-d'),
                'user' => ['id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email],
            ];
        });

        $user = $request->user();
        $conversationData = [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'category' => $conversation->category ? ['id' => $conversation->category->id, 'name' => $conversation->category->name, 'slug' => $conversation->category->slug] : null,
            'name' => $conversation->name,
            'created_by' => $conversation->created_by ? ['id' => $conversation->creator?->id, 'name' => $conversation->creator?->name] : null,
            'is_creator' => $conversation->isCreator($user),
            'is_closed' => $conversation->isClosed(),
            'closed_at' => $conversation->closed_at?->toIso8601String(),
            'participants' => $conversation->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
        ];

        return response()->json([
            'status' => true,
            'conversation' => $conversationData,
            'data' => $messages->items(),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    /**
     * Fetch messages for an existing private (direct) conversation with another user.
     * Does not create the conversation if it doesn't exist yet.
     */
    public function privateIndex(Request $request, int $otherUserId): JsonResponse
    {
        
        $user = $request->user();

        $perPage = (int) $request->get('per_page', 200);
        $perPage = min(max($perPage, 1), 100);

        $conversation = Conversation::findDirectBetween($user, $otherUserId);

        if (! $conversation) {
            return response()->json([
                'status' => true,
                'conversation' => null,
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                ],
            ]);
        }

        $messages = $conversation->messages()
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $messages->getCollection()->transform(function (Message $m) {
            return [
                'id' => $m->id,
                'conversation_id' => $m->conversation_id,
                'user_id' => $m->user_id,
                'body' => $m->body,
                'created_at' => $m->created_at->toIso8601String(),
                'created_time' => $m->created_at->format('H:i A'),
                'created_date' => $m->created_at->format('Y-m-d'),
                'user' => ['id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email],
            ];
        });

        $conversationData = [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'category' => $conversation->category ? ['id' => $conversation->category->id, 'name' => $conversation->category->name, 'slug' => $conversation->category->slug] : null,
            'name' => $conversation->name,
            'created_by' => $conversation->created_by ? ['id' => $conversation->creator?->id, 'name' => $conversation->creator?->name] : null,
            'is_creator' => $conversation->created_by ? $conversation->isCreator($user) : null,
            'is_closed' => $conversation->isClosed(),
            'closed_at' => $conversation->closed_at?->toIso8601String(),
            'participants' => $conversation->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
        ];

        return response()->json([
            'status' => true,
            'conversation' => $conversationData,
            'data' => $messages->items(),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    public function store(Request $request, int $conversationId): JsonResponse
    {
        $validated = $request->validate([
            'body' => 'required|string|max:65535',
        ]);

        $conversation = Conversation::find($conversationId);

        if (! $conversation || ! $conversation->hasParticipant($request->user())) {
            return response()->json([
                'status' => false,
                'message' => 'Conversation not found.',
            ], 404);
        }

        if ($conversation->isClosed()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot send messages to a closed room.',
            ], 422);
        }

        $message = $conversation->messages()->create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        $message->load('user:id,name');

        broadcast(new MessageSent($message))->toOthers();
        $this->broadcastPrivateMessageInbox($message, $conversation);

        return response()->json([
            'status' => true,
            'message' => 'Message sent.',
            'data' => [
                'id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'user_id' => $message->user_id,
                'body' => $message->body,
                'created_at' => $message->created_at->toIso8601String(),
                
                'user' => ['id' => $message->user->id, 'name' => $message->user->name],
            ],
        ], 201);
    }

    /**
     * Send a private (direct) message to another user. Creates the direct conversation if needed.
     */
    public function sendPrivate(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'recipient_id' => ['required', 'string', 'exists:users,id'],
            'body' => 'required|string|max:65535',
        ]);

        $conversation = Conversation::findOrCreateDirect($user, (int) $validated['recipient_id']);

        if ($conversation->isClosed()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot send messages in this conversation.',
            ], 422);
        }

        $message = $conversation->messages()->create([
            'user_id' => $user->id,
            'body' => $validated['body'],
        ]);

        $message->load('user:id,name');

        broadcast(new MessageSent($message))->toOthers();
        $this->broadcastPrivateMessageInbox($message, $conversation);

        $recipient = $conversation->users->firstWhere('id', '!=', $user->id);

        $includeMessages = (bool) ($validated['include_messages'] ?? false);
        if (! $includeMessages) {
            return response()->json([
                'status' => true,
                'message' => 'Message sent.',
                'data' => [
                    'conversation_id' => $conversation->id,
                    'id' => $message->id,
                    'user_id' => $message->user_id,
                    'body' => $message->body,
                    'created_at' => $message->created_at->toIso8601String(),
                    'user' => ['id' => $message->user->id, 'name' => $message->user->name],
                    'recipient' => $recipient ? ['id' => $recipient->id, 'name' => $recipient->name] : null,
                ],
            ], 201);
        }

        $perPage = (int) ($validated['per_page'] ?? 200);
        $perPage = min(max($perPage, 1), 100);

        $messages = $conversation->messages()
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $messages->getCollection()->transform(function (Message $m) {
            return [
                'id' => $m->id,
                'conversation_id' => $m->conversation_id,
                'user_id' => $m->user_id,
                'body' => $m->body,
                'created_at' => $m->created_at->toIso8601String(),
                'created_time' => $m->created_at->format('H:i A'),
                'created_date' => $m->created_at->format('Y-m-d'),
                'user' => ['id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email],
            ];
        });

        $conversationData = [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'category' => $conversation->category ? ['id' => $conversation->category->id, 'name' => $conversation->category->name, 'slug' => $conversation->category->slug] : null,
            'name' => $conversation->name,
            'created_by' => $conversation->created_by ? ['id' => $conversation->creator?->id, 'name' => $conversation->creator?->name] : null,
            'is_creator' => $conversation->created_by ? $conversation->isCreator($user) : null,
            'is_closed' => $conversation->isClosed(),
            'closed_at' => $conversation->closed_at?->toIso8601String(),
            'participants' => $conversation->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
        ];

        return response()->json([
            'status' => true,
            'message' => 'Message sent.',
            'conversation' => $conversationData,
            'data' => [
                'id' => $message->id,
                'conversation_id' => $conversation->id,
                'user_id' => $message->user_id,
                'body' => $message->body,
                'created_at' => $message->created_at->toIso8601String(),
                'user' => ['id' => $message->user->id, 'name' => $message->user->name],
                'recipient' => $recipient ? ['id' => $recipient->id, 'name' => $recipient->name] : null,
            ],
            'messages' => $messages->items(),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ], 201);
    }

    /**
     * Notify the other participant on their private user channel (DM inbox).
     * Conversation channel still receives {@see MessageSent} as `message.sent`.
     */
    private function broadcastPrivateMessageInbox(
        Message $message,
        Conversation $conversation,
        string $eventName = 'private.message',
        ?array $payload = null
    ): void
    {
        if ($conversation->type !== 'direct') {
            return;
        }

        $recipientId = $conversation->users()
            ->where('users.id', '!=', $message->user_id)
            ->value('users.id');

        if ($recipientId) {
            broadcast(new PrivateMessageReceived($message, (int) $recipientId, $eventName, $payload));
        }
    }

    public function update(Request $request, int $conversationId, int $messageId): JsonResponse
    {
        $validated = $request->validate([
            'body' => 'required|string|max:65535',
        ]);

        $conversation = Conversation::find($conversationId);

        if (! $conversation || ! $conversation->hasParticipant($request->user())) {
            return response()->json([
                'status' => false,
                'message' => 'Conversation not found.',
            ], 404);
        }

        $message = $conversation->messages()->where('id', $messageId)->first();

        if (! $message) {
            return response()->json([
                'status' => false,
                'message' => 'Message not found.',
            ], 404);
        }

        if ($message->user_id !== $request->user()->id) {
            return response()->json([
                'status' => false,
                'message' => 'You are not authorized to edit this message.',
            ], 403);
        }

        if ($conversation->isClosed()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot edit messages in a closed room.',
            ], 422);
        }

        $message->body = $validated['body'];
        $message->save();

        $message->load('user:id,name');
        broadcast(new MessageSent($message, 'message.updated'))->toOthers();
        $this->broadcastPrivateMessageInbox($message, $conversation, 'private.message.updated');

        return response()->json([
            'status' => true,
            'message' => 'Message updated.',
            'data' => [
                'id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'user_id' => $message->user_id,
                'body' => $message->body,
                'created_at' => $message->created_at->toIso8601String(),
                'updated_at' => $message->updated_at->toIso8601String(),
                'user' => [
                    'id' => $message->user->id,
                    'name' => $message->user->name,
                ],
            ],
        ]);
    }

    /**
     * Fetch users that have private message conversations with the logged in user.
     * Returns a list of users that have direct/private conversations with the authenticated user.
     */
    public function privateContacts(\Illuminate\Http\Request $request)
    {
        $authUser = $request->user();

        // Find all direct conversations where auth user participates
        $directConvs = \App\Models\Conversation::where('type', 'direct')
            ->whereHas('users', function ($q) use ($authUser) {
                $q->where('user_id', $authUser->id);
            })
            ->with(['users' => function ($q) use ($authUser) {
                // Exclude the current user from the result list
                $q->where('users.id', '!=', $authUser->id);
            }])
            ->get();

        // Flatten out all participants (other than current user)
        $users = $directConvs
            ->pluck('users')
            ->flatten(1)
            ->unique('id')
            ->values()
            ->map(function ($user) {
                return [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    // add other fields as needed
                ];
            });

        return response()->json([
            'status' => true,
            'data' => $users,
        ]);
    }
}