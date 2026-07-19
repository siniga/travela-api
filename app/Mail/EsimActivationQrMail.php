<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EsimActivationQrMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $customerName,
        public readonly ?string $msisdn,
        public readonly ?string $iccid,
        public readonly ?string $orderReference,
        public readonly string $qrDataUri,
        public readonly string $dashboardUrl = 'https://thetravela.com/dashboard',
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Travela eSIM activation QR code',
        );
    }

    public function content(): Content
    {
        $name = e($this->customerName);
        $dashboardUrl = e($this->dashboardUrl);
        $qrDataUri = e($this->qrDataUri);
        $msisdn = $this->msisdn ? e($this->formatMsisdn($this->msisdn)) : null;
        $iccid = $this->iccid ? e($this->iccid) : null;
        $orderReference = $this->orderReference ? e($this->orderReference) : null;

        $details = '';
        if ($msisdn) {
            $details .= "<p style='color:#666;font-size:15px;margin:0 0 8px;'><strong>Number:</strong> {$msisdn}</p>";
        }
        if ($iccid) {
            $details .= "<p style='color:#666;font-size:15px;margin:0 0 8px;'><strong>ICCID:</strong> {$iccid}</p>";
        }
        if ($orderReference) {
            $details .= "<p style='color:#666;font-size:15px;margin:0 0 8px;'><strong>Order reference:</strong> {$orderReference}</p>";
        }

        return new Content(
            htmlString: "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;'>
                    <h2 style='color:#112116;text-align:center;margin-bottom:8px;'>Your eSIM is ready</h2>
                    <p style='color:#666;font-size:16px;line-height:1.5;text-align:center;'>
                        Hi {$name}, scan the QR code below on your phone to install your Travela eSIM.
                    </p>
                    <div style='text-align:center;margin:28px 0;'>
                        <img src='{$qrDataUri}' alt='eSIM activation QR code' width='260' height='260' style='display:inline-block;border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;' />
                    </div>
                    {$details}
                    <p style='color:#666;font-size:15px;line-height:1.6;'>
                        On iPhone, open <strong>Settings → Cellular → Add eSIM</strong> and scan this code.
                        On Android, open <strong>Settings → Network &amp; internet → SIMs → Add eSIM</strong> and scan the code.
                    </p>
                    <p style='text-align:center;margin:24px 0;'>
                        <a href='{$dashboardUrl}' style='display:inline-block;background-color:#17cf54;color:#112116;padding:12px 24px;text-decoration:none;border-radius:8px;font-weight:bold;'>
                            Open dashboard
                        </a>
                    </p>
                    <p style='color:#666;font-size:14px;line-height:1.5;'>
                        You can also tap <strong>Activate eSIM</strong> in your dashboard if you prefer not to scan a QR code.
                    </p>
                    <p style='color:#999;font-size:12px;line-height:1.5;'>
                        Keep this email private — anyone with this QR code can install your eSIM profile.
                    </p>
                    <hr style='border:none;border-top:1px solid #eee;margin:20px 0;'>
                    <p style='color:#999;font-size:12px;text-align:center;'>
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

    private function formatMsisdn(string $msisdn): string
    {
        $trimmed = trim($msisdn);

        return str_starts_with($trimmed, '+') ? $trimmed : '+'.$trimmed;
    }
}
