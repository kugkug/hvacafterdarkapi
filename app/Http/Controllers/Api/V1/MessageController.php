<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Events\MessageSent;
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
}