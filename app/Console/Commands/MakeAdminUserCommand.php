<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MakeAdminUserCommand extends Command
{
    protected $signature = 'rugby:make-admin
                            {email : Admin email address}
                            {--name= : Display name (defaults to email local-part)}
                            {--password= : Initial password (defaults to a random 16-char string, printed once)}';

    protected $description = 'Create or promote an admin user for league management.';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $name = $this->option('name') ?: Str::before($email, '@');
        $password = $this->option('password') ?: Str::random(16);
        $passwordWasGenerated = ! $this->option('password');

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update([
                'name' => $name,
                'role' => User::ROLE_ADMIN,
                'password' => Hash::make($password),
            ]);
            $this->info("Updated existing user {$email} to admin.");
        } else {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'role' => User::ROLE_ADMIN,
                'password' => Hash::make($password),
            ]);
            $this->info("Created admin user {$email}.");
        }

        if ($passwordWasGenerated) {
            $this->warn("Generated password (shown once): {$password}");
        }

        return self::SUCCESS;
    }
}
