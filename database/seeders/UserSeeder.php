<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create admin user
        User::updateOrCreate(
            ['email' => 'admin@travela.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password123'),
                'email_verified_at' => now(),
            ]
        );

        // Create test users
        User::updateOrCreate(
            ['email' => 'john.doe@example.com'],
            [
                'name' => 'John Doe',
                'password' => Hash::make('password123'),
                'email_verified_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'jane.smith@example.com'],
            [
                'name' => 'Jane Smith',
                'password' => Hash::make('password123'),
                'email_verified_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'mike.johnson@example.com'],
            [
                'name' => 'Mike Johnson',
                'password' => Hash::make('password123'),
                'email_verified_at' => null, // Not verified
            ]
        );

        User::updateOrCreate(
            ['email' => 'sarah.lee@example.com'],
            [
                'name' => 'Sarah Lee',
                'password' => Hash::make('password123'),
                'email_verified_at' => now(),
            ]
        );
    }
}

