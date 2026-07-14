<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('user:make-admin {email}', function () {
    $email = (string) $this->argument('email');

    $user = \App\Models\User::where('email', $email)->first();

    if (! $user) {
        $this->error("User not found: {$email}");
        return 1;
    }

    $user->role = 'admin';
    $user->save();

    $this->info("User promoted to admin: {$email}");
    return 0;
})->purpose('Promote a user to admin role');

Artisan::command('resend:check', function () {
    $key = (string) config('services.resend.key');
    if ($key === '') {
        $this->error('RESEND_KEY is not set in .env');
        return 1;
    }

    /** @var \App\Services\ResendMailConfigurator $configurator */
    $configurator = app(\App\Services\ResendMailConfigurator::class);
    $verified = $configurator->verifiedDomainNames();
    $resolvedFrom = $configurator->resolvedFromAddress();

    $this->info('Mailer: '.config('mail.default'));
    $this->line('Configured from: '.config('mail.from.address'));
    $this->line('Resolved from: '.$resolvedFrom);

    if ($verified === []) {
        $this->warn('No verified Resend domains found.');
        $this->line('Add and verify a domain at https://resend.com/domains');
        return 1;
    }

    $this->info('Verified domains:');
    foreach ($verified as $domain) {
        $this->line(" - {$domain}");
    }

    $configuredDomain = substr(strrchr((string) config('mail.from.address'), '@'), 1) ?: '';
    if ($configuredDomain !== '' && ! in_array($configuredDomain, $verified, true)) {
        $this->warn("Configured MAIL_FROM domain ({$configuredDomain}) is not verified.");
        $this->line("Sending will use {$resolvedFrom} instead.");
    }

    return 0;
})->purpose('Inspect Resend domain verification and sender address');

Artisan::command('mail:test {email}', function () {
    $email = (string) $this->argument('email');

    try {
        \Illuminate\Support\Facades\Mail::to($email)->send(
            new \App\Mail\EmailVerificationCodeMail('123456')
        );
    } catch (\Throwable $e) {
        $this->error('Mail failed: '.$e->getMessage());
        return 1;
    }

    $this->info("Test verification email sent to {$email}");
    return 0;
})->purpose('Send a test verification email through the configured mailer');
