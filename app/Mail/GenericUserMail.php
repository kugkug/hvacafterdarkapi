<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GenericUserMail extends Mailable
{
    use Queueable, SerializesModels;
    public $subject;
    public $message;

    public function __construct(
        string $subject,
        string $message
    ) {
        $this->subject = $subject;
        $this->message = $message;
    }

    // public function envelope(): Envelope
    // {
    //     return new Envelope(
    //         subject: $this->subject
    //     );
    // }

    public function build(): self
    {
        return $this->subject($this->subject)
            ->view('emails.generic-user-mail')
            ->with([
                'subjectText' => $this->subject,
                'messageText' => $this->message,
            ]);
    }
}