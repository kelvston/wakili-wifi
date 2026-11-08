<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $email = config('admin.email');
        $configuredPassword = config('admin.password');
        $password = $configuredPassword ?: Str::random(40);

        // dd($email, $password);
        $admin = User::query()->firstOrCreate([
            'email' => $email,
        ], [
            'name' => config('admin.name'),
            'password' => $password,
        ]);

        if (! $admin->email_verified_at) {
            $admin->forceFill(['email_verified_at' => now()])->save();
        }

        if ($admin->wasRecentlyCreated) {
            $this->command?->info("Admin account created: {$email}");

            if (! $configuredPassword) {
                $this->command?->warn("One-time admin password: {$password}");
            } else {
                $this->command?->info('Admin password was read from ADMIN_PASSWORD.');
            }
        } else {
            $this->command?->info("Admin account already exists: {$email}");
        }
    }
}
