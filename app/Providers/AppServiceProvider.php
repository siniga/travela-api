<?php

namespace App\Providers;

use App\Services\ResendMailConfigurator;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mime\Address;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(MessageSending::class, function (MessageSending $event): void {
            if (! $this->usesResendMailer()) {
                return;
            }

            $fromAddress = app(ResendMailConfigurator::class)->resolvedFromAddress();
            $fromName = (string) config('mail.from.name', 'Travela');

            // Symfony Email::from() takes Address args — not (email, name) positional pairs.
            $event->message->from(new Address($fromAddress, $fromName));
        });
    }

    private function usesResendMailer(): bool
    {
        $mailer = (string) config('mail.default');

        if ($mailer === 'resend') {
            return true;
        }

        if (str_contains($mailer, 'resend')) {
            return true;
        }

        $mailers = (array) config("mail.mailers.{$mailer}.mailers", []);

        return in_array('resend', $mailers, true);
    }
}
