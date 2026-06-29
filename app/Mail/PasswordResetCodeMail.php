<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public $code,
        public string $setPasswordUrl = 'https://thetravela.com/set-password',
    ) {
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Password Reset Code - Travela',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $setPasswordUrl = e($this->setPasswordUrl);
        $code = e($this->code);

        return new Content(
            htmlString: "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                    <h2 style='color: #333; text-align: center;'>Password Reset Code</h2>
                    <p style='color: #666; font-size: 16px; line-height: 1.5;'>
                        You requested a password reset for your Travela account. Use the code below on the set-password page:
                    </p>
                    <p style='text-align: center; margin: 24px 0;'>
                        <a href='{$setPasswordUrl}' style='display: inline-block; background-color: #2563eb; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold;'>
                            Set your password
                        </a>
                    </p>
                    <div style='background-color: #f5f5f5; padding: 20px; text-align: center; margin: 20px 0; border-radius: 8px;'>
                        <h1 style='color: #333; font-size: 32px; letter-spacing: 8px; margin: 0; font-family: monospace;'>{$code}</h1>
                    </div>
                    <p style='color: #666; font-size: 14px;'>
                        This code will expire in 15 minutes. If you didn't request this reset, please ignore this email.
                    </p>
                    <hr style='border: none; border-top: 1px solid #eee; margin: 20px 0;'>
                    <p style='color: #999; font-size: 12px; text-align: center;'>
                        This is an automated message from Travela. Please do not reply to this email.
                    </p>
                </div>
            ",
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
