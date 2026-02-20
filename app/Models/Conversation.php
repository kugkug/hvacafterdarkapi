<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
}