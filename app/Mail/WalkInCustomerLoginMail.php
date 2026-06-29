<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WalkInCustomerLoginMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $customerName,
        public readonly string $code,
        public readonly string $loginUrl = 'https://thetravela.com/',
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Travela SIM — Sign in to your account',
        );
    }

    public function content(): Content
    {
        $name = e($this->customerName);
        $loginUrl = e($this->loginUrl);
        $code = e($this->code);

        return new Content(
            htmlString: "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                    <h2 style='color: #333; text-align: center;'>Welcome to Travela</h2>
                    <p style='color: #666; font-size: 16px; line-height: 1.5;'>
                        Hi {$name}, your physical Travela SIM has been registered at our counter.
                        Sign in to manage your account and bundles:
                    </p>
                    <p style='text-align: center; margin: 24px 0;'>
                        <a href='{$loginUrl}' style='display: inline-block; background-color: #2563eb; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold;'>
                            Go to Travela
                        </a>
                    </p>
                    <p style='color: #666; font-size: 16px; line-height: 1.5;'>
                        To set your password, open
                        <a href='{$loginUrl}'>{$loginUrl}</a>
                        and use <strong>Forgot password</strong> with this one-time code:
                    </p>
                    <div style='background-color: #f5f5f5; padding: 20px; text-align: center; margin: 20px 0; border-radius: 8px;'>
                        <h1 style='color: #333; font-size: 32px; letter-spacing: 8px; margin: 0; font-family: monospace;'>{$code}</h1>
                    </div>
                    <p style='color: #666; font-size: 14px;'>
                        This code expires in 15 minutes. If you did not receive a SIM from Travela, you can ignore this email.
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
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
