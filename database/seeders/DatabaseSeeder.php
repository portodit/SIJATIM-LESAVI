<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\SeedDataPackage;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // A committed data package (database/seed-data) replaces the generic seed: masters, links and invoice progress.
        if (SeedDataPackage::exists()) {
            $this->call(InvoiceProgressSeeder::class);

            return;
        }

        // Seed master data first (order matters!)
        $this->call([
            OfficeTypesSeeder::class,
            LocationCategoriesSeeder::class,
            ServicesSeeder::class,
            TypeOrdersSeeder::class,
            BandwidthOptionsSeeder::class,
            PriceMastersSeeder::class, // Setelah master data lain ada
            InvoiceStepsSeeder::class,
        ]);

        // Seed link data from CSV (after master data exists)
        $this->call([
            BankJatimLinksSeeder::class,
        ]);

        // Create test user (skip if already exists)
        if (! User::where('email', 'test@example.com')->exists()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }
    }
}
