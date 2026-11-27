<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class PlatformStaffCreate extends Command
{
    protected $signature = 'platform:staff-create {name} {email}';

    protected $description = 'Create an HQ (platform) staff account and print a one-time password';

    public function handle(TenantContext $context): int
    {
        $email = Str::lower((string) $this->argument('email'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Enter a valid email address.');

            return self::FAILURE;
        }

        $password = Str::password(16);

        $created = $context->runAs(null, function () use ($email, $password): bool {
            if (User::query()->whereNull('tenant_id')->whereRaw('lower(email) = ?', [$email])->exists()) {
                return false;
            }

            User::query()->create([
                'name' => (string) $this->argument('name'),
                'email' => $email,
                'password' => $password,
                'email_verified_at' => now(),
            ]);

            return true;
        }, platform: true);

        if (! $created) {
            $this->error('An HQ account with this email already exists.');

            return self::FAILURE;
        }

        $this->info("HQ account created for {$email}.");
        $this->line("Temporary password: {$password}");
        $this->warn('Ask them to sign in, change the password and turn on two-factor authentication.');

        return self::SUCCESS;
    }
}
