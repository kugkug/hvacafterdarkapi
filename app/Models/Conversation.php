<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'category_id',
        'name',
        'created_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ConversationCategory::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_user')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id', 'asc');
    }

    public function bans(): HasMany
    {
        return $this->hasMany(ConversationBan::class);
    }

    public function hasParticipant(User $user): bool
    {
        return $this->users()->where('user_id', $user->id)->exists();
    }

    public function isCreator(User $user): bool
    {
        return $this->created_by === $user->id;
    }

    public function isBanned(User $user): bool
    {
        return $this->bans()->where('user_id', $user->id)->exists();
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * Find an existing direct conversation between two users (no creation).
     */
    public static function findDirectBetween(User $user, int $otherUserId): ?self
    {
        if ($otherUserId === $user->id) {
            return null;
        }

        $sortedIds = collect([$user->id, $otherUserId])->sort()->values()->toArray();

        $existing = static::where('type', 'direct')
            ->whereHas('users', fn ($q) => $q->where('user_id', $user->id))
            ->with('users:id,name')
            ->get()
            ->first(fn (self $c) => $c->users->pluck('id')->sort()->values()->toArray() === $sortedIds);

        return $existing?->loadMissing(['users:id,name,email', 'creator:id,name', 'category:id,name,slug']);
    }

    /**
     * Find an existing direct conversation between two users, or create one.
     */
    public static function findOrCreateDirect(User $user, int $otherUserId): self
    {
        if ($otherUserId === $user->id) {
            throw new \InvalidArgumentException('Cannot create a direct conversation with yourself.');
        }

        $sortedIds = collect([$user->id, $otherUserId])->sort()->values()->toArray();

        $existing = static::where('type', 'direct')
            ->whereHas('users', fn ($q) => $q->where('user_id', $user->id))
            ->with('users:id,name')
            ->get()
            ->first(fn (self $c) => $c->users->pluck('id')->sort()->values()->toArray() === $sortedIds);

        if ($existing) {
            return $existing->loadMissing(['users:id,name,email', 'creator:id,name', 'category:id,name,slug']);
        }

        return DB::transaction(function () use ($sortedIds) {
            $c = static::create([
                'type' => 'direct',
                'category_id' => null,
                'name' => null,
                'created_by' => null,
            ]);
            $c->users()->attach($sortedIds);

            return $c->load(['users:id,name,email', 'creator:id,name', 'category:id,name,slug']);
        });
    }
}