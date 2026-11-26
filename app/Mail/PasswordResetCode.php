<?php

namespace App\Mail;

use App\Services\PasswordResetCodes;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordResetCode extends Mailable
{
    public function __construct(#[\SensitiveParameter] private string $code) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('password-reset.subject', ['app' => config('app.name')]),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            text: 'mail.password-reset-code',
            with: ['code' => $this->code, 'minutes' => PasswordResetCodes::EXPIRY_MINUTES],
        );
    }
}
