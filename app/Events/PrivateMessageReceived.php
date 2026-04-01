<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Direct / private DM delivery on the recipient's personal channel (inbox),
 * in addition to {@see MessageSent} on the conversation channel.
 */
class PrivateMessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Message $message,
        public int $recipientUserId,
        public string $eventName = 'private.message',
        public ?array $payload = null
    ) {}

    public function broadcastOn(): array
    {
        // Use the same private channel as `routes/channels.php` (`App.Models.User.{id}`).
        // Short names like `user.{id}` often fail channel matching → 403 on `/broadcasting/auth`.
        return [
            new PrivateChannel('user.' . $this->recipientUserId),
        ];
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    public function broadcastWith(): array
    {
        if (is_array($this->payload)) {
            return $this->payload;
        }

        $this->message->loadMissing(['user:id,name', 'conversation:id,type']);

        return [
            'conversation_type' => 'direct',
            'message' => [
                'id' => $this->message->id,
                'conversation_id' => $this->message->conversation_id,
                'user_id' => $this->message->user_id,
                'body' => $this->message->body,
                'created_at' => $this->message->created_at->toIso8601String(),
                'updated_at' => $this->message->updated_at?->toIso8601String(),
                'user' => [
                    'id' => $this->message->user->id,
                    'name' => $this->message->user->name,
                ],
            ],
        ];
    }
}