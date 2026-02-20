<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConversationCategory extends Model
{
    use HasFactory;

    protected $table = 'conversation_categories';

    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'category_id');
    }

    protected static function booted(): void
    {
        static::creating(function (ConversationCategory $category) {
            if (empty($category->slug) && ! empty($category->name)) {
                $category->slug = \Illuminate\Support\Str::slug($category->name);
            }
        });
    }
}
