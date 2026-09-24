<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EsimAssignmentDueMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $customerName,
        public readonly string $activationDate,
        public readonly ?string $orderReference,
        public readonly string $dashboardUrl = 'https://thetravela.com/dashboard',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your eSIM activation date is here',
        );
    }

    public function content(): Content
    {
        $name = e($this->customerName);
        $date = e($this->activationDate);
        $dashboardUrl = e($this->dashboardUrl);
        $orderReference = $this->orderReference ? e($this->orderReference) : null;
        $orderLine = $orderReference
            ? "<p style='color:#666;font-size:15px;margin:0 0 8px;'><strong>Order reference:</strong> {$orderReference}</p>"
            : '';

        return new Content(
            htmlString: "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;'>
                    <h2 style='color:#112116;text-align:center;margin-bottom:8px;'>Your eSIM activation date is here</h2>
                    <p style='color:#666;font-size:16px;line-height:1.5;'>
                        Hi {$name}, your eSIM activation date ({$date}) has been reached.
                        A number is not assigned yet.
                    </p>
                    {$orderLine}
                    <p style='color:#666;font-size:15px;line-height:1.6;'>
                        Open your dashboard to assign your SIM now, or choose a later eSIM activation date.
                    </p>
                    <p style='text-align:center;margin:28px 0;'>
                        <a href='{$dashboardUrl}' style='display:inline-block;background-color:#17cf54;color:#112116;padding:12px 24px;text-decoration:none;border-radius:8px;font-weight:bold;'>
                            Open dashboard
                        </a>
                    </p>
                    <hr style='border:none;border-top:1px solid #eee;margin:20px 0;'>
                    <p style='color:#999;font-size:12px;text-align:center;'>
                        This is an automated message from Travela. Please do not reply to this email.
                    </p>
                </div>
            ",
        );
    }
}
