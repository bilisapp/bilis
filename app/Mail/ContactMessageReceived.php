<?php

namespace App\Mail;

use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The notification that someone wrote in.
 *
 * Reply-to is the sender, so answering is one keystroke and never involves
 * copying an address out of the body. The row in `contact_messages` is the
 * record; this is only the nudge to go and read it.
 */
class ContactMessageReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ContactMessage $contactMessage) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[Bilis contact] %s — %s',
                $this->contactMessage->topic->label(),
                $this->contactMessage->name,
            ),
            replyTo: [new Address($this->contactMessage->email, $this->contactMessage->name)],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.contact.received');
    }
}
