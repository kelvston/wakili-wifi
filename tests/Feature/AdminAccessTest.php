<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_disabled_but_admin_login_is_available(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/login')->assertOk();
    }

    public function test_database_seeder_creates_a_verified_admin_and_is_idempotent(): void
    {
        config([
            'admin.name' => 'Wi-Fi Administrator',
            'admin.email' => 'portal-admin@example.test',
            'admin.password' => 'test-admin-password',
        ]);

        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'portal-admin@example.test')->firstOrFail();

        $this->assertSame('Wi-Fi Administrator', $admin->name);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check('test-admin-password', $admin->password));

        $this->post('/login', [
            'email' => 'portal-admin@example.test',
            'password' => 'test-admin-password',
        ])->assertRedirect('/dashboard');

        $this->get('/dashboard')->assertOk();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::query()->where('email', 'portal-admin@example.test')->count());
    }
}
