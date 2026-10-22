<?php

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent synchronously from inside SendTemplatedEmail, which owns queuing and retries. */
class TemplatedMail extends Mailable
{
    public function __construct(
        public string $mailSubject,
        public string $htmlBody,
        public array $files = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlBody);
    }

    public function attachments(): array
    {
        return collect($this->files)->map(fn ($f) => Attachment::fromStorageDisk($f['disk'], $f['path'])
            ->as($f['name'])->withMime($f['mime']))->all();
    }
}
