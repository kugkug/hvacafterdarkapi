<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $conversations = $user->conversations()
            ->with(['users:id,name', 'creator:id,name', 'category:id,name,slug'])
            ->with(['messages' => fn ($q) => $q->latest()->limit(1)->with('user:id,name')])
            ->orderByDesc('conversations.updated_at')
            ->get();

        $list = $conversations->map(function (Conversation $c) use ($user) {
            $lastMessage = $c->messages->first();
            $pivot = $c->users->firstWhere('id', $user->id)?->pivot;
            $lastReadAt = $pivot?->last_read_at;
            $unreadCount = $lastReadAt
                ? $c->messages()->where('user_id', '!=', $user->id)->where('created_at', '>', $lastReadAt)->count()
                : $c->messages()->where('user_id', '!=', $user->id)->count();
            $otherUsers = $c->users->where('id', '!=', $user->id)->values();
            return [
                'id' => $c->id,
                'type' => $c->type,
                'category' => $c->category ? ['id' => $c->category->id, 'name' => $c->category->name, 'slug' => $c->category->slug] : null,
                'name' => $c->name ?? $otherUsers->pluck('name')->join(', '),
                'created_by' => $c->created_by ? ['id' => $c->creator?->id ?? $c->created_by, 'name' => $c->creator?->name] : null,
                'is_creator' => $c->created_by ? $c->isCreator($user) : null,
                'is_closed' => $c->isClosed(),
                'closed_at' => $c->closed_at?->toIso8601String(),
                'participants' => $c->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
                'last_message' => $lastMessage ? [
                    'id' => $lastMessage->id,
                    'body' => $lastMessage->body,
                    'created_at' => $lastMessage->created_at->toIso8601String(),
                    'user' => ['id' => $lastMessage->user->id, 'name' => $lastMessage->user->name],
                ] : null,
                'unread_count' => $unreadCount,
                'updated_at' => $c->updated_at->toIso8601String(),
            ];
        });

        return response()->json([
            'status' => true,
            'data' => $list,
        ]);
    }

    public function indexGroupedByCategory(Request $request): JsonResponse
    {
        $categories = ConversationCategory::with([
            'conversations' => function ($q) {
                $q->where('type', 'group')
                    ->with(['users:id,name', 'creator:id,name'])
                    ->withCount('users')
                    ->orderByDesc('updated_at');
            },
        ])->orderBy('name')->get();

        $user = $request->user();
        $data = $categories
            ->filter(fn (ConversationCategory $cat) => $cat->conversations->isNotEmpty())
            ->map(function (ConversationCategory $cat) use ($user) {
                $conversations = $cat->conversations->map(function (Conversation $c) use ($user) {
                    $payload = $this->conversationData($c, $user);
                    $payload['participants_count'] = $c->users_count ?? $c->users->count();

                    return $payload;
                });

                return [
                    'category' => [
                        'id' => $cat->id,
                        'name' => $cat->name,
                        'slug' => $cat->slug,
                        'description' => $cat->description,
                    ],
                    'conversations' => $conversations->values()->all(),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'status' => true,
            'data' => $data,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer|exists:users,id',
            'name' => 'nullable|string|max:255',
            'type' => 'nullable|in:direct,group',
            'category_id' => 'nullable|integer|exists:conversation_categories,id',
        ]);

        $user = $request->user();
        $userIds = array_values(array_unique(array_merge($validated['user_ids'] ?? [], [$user->id])));
        $type = $validated['type'] ?? (count($userIds) > 2 ? 'group' : 'direct');

        if ($type === 'group' && empty($validated['category_id'] ?? null)) {
            return response()->json([
                'status' => false,
                'message' => 'Category is required for group conversations.',
            ], 422);
        }

        // Direct chat: require exactly 2 users
        if ($type === 'direct') {
            if (count($userIds) !== 2) {
                return response()->json([
                    'status' => false,
                    'message' => 'Direct chat requires exactly one other user.',
                ], 422);
            }
            $sortedIds = collect($userIds)->sort()->values()->toArray();
            $existing = Conversation::where('type', 'direct')
                ->whereHas('users', fn ($q) => $q->where('user_id', $user->id))
                ->with('users:id,name')
                ->get()
                ->first(fn (Conversation $c) => $c->users->pluck('id')->sort()->values()->toArray() === $sortedIds);
            if ($existing) {
                return response()->json([
                    'status' => true,
                    'message' => 'Conversation already exists.',
                    'data' => $this->conversationData($existing, $user),
                ]);
            }
            $conversation = DB::transaction(function () use ($userIds, $user, $validated) {
                $c = Conversation::create([
                    'type' => 'direct',
                    'category_id' => $validated['category_id'] ?? null,
                    'name' => null,
                    'created_by' => null,
                ]);
                $c->users()->attach($userIds);
                return $c->load(['users:id,name', 'category:id,name,slug']);
            });
            return response()->json([
                'status' => true,
                'message' => 'Conversation created.',
                'data' => $this->conversationData($conversation, $user),
            ], 201);
        }

        // Group / room: allow opening empty room (creator only)
        $conversation = DB::transaction(function () use ($userIds, $user, $validated, $type) {
            $conversation = Conversation::create([
                'type' => $type,
                'category_id' => $validated['category_id'],
                'name' => $validated['name'] ?? null,
                'created_by' => $user->id,
            ]);
            $conversation->users()->attach($userIds);
            return $conversation->load(['users:id,name', 'creator:id,name', 'category:id,name,slug']);
        });

        return response()->json([
            'status' => true,
            'message' => 'Conversation created.',
            'data' => $this->conversationData($conversation, $user),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $conversation = Conversation::find($id);

        if (! $conversation || ! $conversation->hasParticipant($request->user())) {
            return response()->json([
                'status' => false,
                'message' => 'Conversation not found.',
            ], 404);
        }

        $conversation->load(['users:id,name', 'creator:id,name', 'category:id,name,slug']);

        return response()->json([
            'status' => true,
            'data' => $this->conversationData($conversation, $request->user()),
        ]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $conversation = Conversation::find($id);

        if (! $conversation || ! $conversation->hasParticipant($request->user())) {
            return response()->json([
                'status' => false,
                'message' => 'Conversation not found.',
            ], 404);
        }

        $conversation->users()->updateExistingPivot($request->user()->id, [
            'last_read_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Marked as read.',
        ]);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $conversation = Conversation::find($id);
        $user = $request->user();

        if (! $conversation || ! $conversation->hasParticipant($user)) {
            return response()->json(['status' => false, 'message' => 'Conversation not found.'], 404);
        }

        if (! $conversation->isCreator($user)) {
            return response()->json(['status' => false, 'message' => 'Only the room creator can close the room.'], 403);
        }

        if ($conversation->isClosed()) {
            return response()->json(['status' => false, 'message' => 'Room is already closed.'], 422);
        }

        $conversation->update(['closed_at' => now()]);
        $conversation->load(['users:id,name', 'creator:id,name']);

        return response()->json([
            'status' => true,
            'message' => 'Room closed.',
            'data' => $this->conversationData($conversation, $user),
        ]);
    }

    public function invite(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'integer|exists:users,id',
        ]);

        $conversation = Conversation::with('bans')->find($id);
        $user = $request->user();

        if (! $conversation || ! $conversation->hasParticipant($user)) {
            return response()->json(['status' => false, 'message' => 'Conversation not found.'], 404);
        }

        if (! $conversation->isCreator($user)) {
            return response()->json(['status' => false, 'message' => 'Only the room creator can invite users.'], 403);
        }

        if ($conversation->type === 'direct') {
            return response()->json(['status' => false, 'message' => 'Cannot invite to a direct chat.'], 422);
        }

        if ($conversation->isClosed()) {
            return response()->json(['status' => false, 'message' => 'Cannot invite to a closed room.'], 422);
        }

        $toInvite = array_unique($validated['user_ids']);
        $bannedIds = $conversation->bans()->whereIn('user_id', $toInvite)->pluck('user_id')->toArray();
        $alreadyIn = $conversation->users()->whereIn('user_id', $toInvite)->pluck('user_id')->toArray();
        $newUsers = array_diff($toInvite, $bannedIds, $alreadyIn);

        if (empty($newUsers)) {
            return response()->json([
                'status' => false,
                'message' => 'No valid users to invite. Some may be banned or already in the room.',
            ], 422);
        }

        $conversation->users()->attach($newUsers);
        $conversation->load('users:id,name');

        return response()->json([
            'status' => true,
            'message' => 'Users invited.',
            'data' => $this->conversationData($conversation, $user),
        ]);
    }

    public function remove(Request $request, int $id, int $userId): JsonResponse
    {
        $conversation = Conversation::find($id);
        $user = $request->user();

        if (! $conversation || ! $conversation->hasParticipant($user)) {
            return response()->json(['status' => false, 'message' => 'Conversation not found.'], 404);
        }

        if (! $conversation->isCreator($user)) {
            return response()->json(['status' => false, 'message' => 'Only the room creator can remove users.'], 403);
        }

        if ((int) $userId === $user->id) {
            return response()->json(['status' => false, 'message' => 'Use leave instead of removing yourself.'], 422);
        }

        $targetUser = User::find($userId);
        if (! $targetUser || ! $conversation->hasParticipant($targetUser)) {
            return response()->json(['status' => false, 'message' => 'User is not in this room.'], 422);
        }

        $conversation->users()->detach($targetUser->id);
        $conversation->load('users:id,name');

        return response()->json([
            'status' => true,
            'message' => 'User removed.',
            'data' => $this->conversationData($conversation, $user),
        ]);
    }

    public function ban(Request $request, int $id, int $userId): JsonResponse
    {
        $conversation = Conversation::find($id);
        $user = $request->user();

        if (! $conversation || ! $conversation->hasParticipant($user)) {
            return response()->json(['status' => false, 'message' => 'Conversation not found.'], 404);
        }

        if (! $conversation->isCreator($user)) {
            return response()->json(['status' => false, 'message' => 'Only the room creator can ban users.'], 403);
        }

        if ((int) $userId === $user->id) {
            return response()->json(['status' => false, 'message' => 'Cannot ban yourself.'], 422);
        }

        $target = User::find($userId);
        if (! $target) {
            return response()->json(['status' => false, 'message' => 'User not found.'], 404);
        }

        if ($conversation->isBanned($target)) {
            return response()->json(['status' => false, 'message' => 'User is already banned.'], 422);
        }

        DB::transaction(function () use ($conversation, $userId, $user) {
            $conversation->bans()->create([
                'user_id' => $userId,
                'banned_by' => $user->id,
            ]);
            $conversation->users()->detach($userId);
        });

        $conversation->load('users:id,name');

        return response()->json([
            'status' => true,
            'message' => 'User banned.',
            'data' => $this->conversationData($conversation, $user),
        ]);
    }

    private function conversationData(Conversation $c, $user): array
    {
        return [
            'id' => $c->id,
            'type' => $c->type,
            'category' => $c->category ? ['id' => $c->category->id, 'name' => $c->category->name, 'slug' => $c->category->slug] : null,
            'name' => $c->name,
            'created_by' => $c->created_by ? ['id' => $c->creator?->id, 'name' => $c->creator?->name] : null,
            'is_creator' => $c->isCreator($user),
            'is_closed' => $c->isClosed(),
            'closed_at' => $c->closed_at?->toIso8601String(),
            'participants' => $c->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
        ];
    }
}
