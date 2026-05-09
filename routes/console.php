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
