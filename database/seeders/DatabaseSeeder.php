<?php

namespace Database\Seeders;

use App\Models\User;
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
        $adminEmail = (string) env('ADMIN_USER_EMAIL', 'admin@example.com');

        User::query()->updateOrCreate([
            'email' => $adminEmail,
        ], [
            'name' => (string) env('ADMIN_USER_NAME', 'admin'),
            'password' => (string) env('ADMIN_USER_PASSWORD', 'admin'),
        ]);
    }
}
