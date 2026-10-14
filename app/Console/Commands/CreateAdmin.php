<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email} {--name=Admin}';

    protected $description = 'Create (or reset the password of) an admin user';

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = password(
            label: 'Password (min 8 characters)',
            required: true,
            validate: fn ($v) => strlen($v) < 8 ? 'Use at least 8 characters.' : null,
        );

        $user = User::firstOrNew(['email' => $email]);
        $user->fill([
            'name' => $user->exists ? $user->name : $this->option('name'),
            'password' => $password,
            'role' => User::ROLE_ADMIN,
            'client_id' => null,
        ])->save();

        $this->info("Admin {$email} ".($user->wasRecentlyCreated ? 'created.' : 'updated.'));

        return self::SUCCESS;
    }
}
