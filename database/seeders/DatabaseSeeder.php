<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Call all seeders in the correct order (respecting foreign key constraints)
        $this->call([
            UserSeeder::class,
            CountrySeeder::class,
            ProviderSeeder::class,
            BundleTypeSeeder::class,
            CountryProviderSeeder::class,
            BundleSeeder::class,
            EsimSeeder::class,
            KycSeeder::class,
            OrderSeeder::class, // Add order seeder after all dependencies
        ]);

        $this->command->info('All seeders completed successfully!');
    }
}
