<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) config('cass.admin_email'));
        $password = (string) config('cass.admin_password');

        if ($email === '' || strlen($password) < 12) {
            throw new RuntimeException('CASS_ADMIN_EMAIL and CASS_ADMIN_PASSWORD (12+ characters) must be set to seed the platform admin.');
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $user->name ?: 'Platform Admin',
            'password' => $password,
            'is_platform_admin' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
    }
}
