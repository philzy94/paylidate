<?php

namespace Database\Seeders;

use App\Product;
use App\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * An admin account is created from SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD.
     * Sample users and products are only generated outside production.
     */
    public function run(): void
    {
        $email = config('app.seed_admin.email');
        $password = config('app.seed_admin.password');

        if (is_string($email) && is_string($password) && $email !== '' && $password !== '') {
            User::updateOrCreate(['email' => $email], [
                'name' => 'Paylidate Admin',
                'password' => Hash::make($password),
                'is_admin' => true,
                'is_staff' => true,
            ]);
        }

        if (! app()->isProduction()) {
            User::factory()->count(4)->create();
            Product::factory()->count(20)->create();
        }
    }
}
