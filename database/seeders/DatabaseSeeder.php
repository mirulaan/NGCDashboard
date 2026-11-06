<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::updateOrCreate(
            ['name' => 'admin'],
            [
                'email' => 'admin@factory.local', // Email is required by DB, but we will login with name
                'password' => Hash::make('admin123')
            ]
        );

        // Create SuperAdmin
        User::updateOrCreate(
            ['name' => 'superadmin'],
            [
                'email' => 'superadmin@factory.local',
                'password' => Hash::make('superadmin123')
            ]
        );
    }
}
