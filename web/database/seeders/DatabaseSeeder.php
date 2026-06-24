<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $adminPassword = env('SMARTPROT_ADMIN_PASSWORD');

        if (! $adminPassword) {
            return;
        }

        User::updateOrCreate(
            ['email' => env('SMARTPROT_ADMIN_EMAIL', 'admin@smartprot.local')],
            [
                'name' => env('SMARTPROT_ADMIN_NAME', 'SmartProt Admin'),
                'password' => Hash::make($adminPassword),
            ]
        );
    }
}