<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * There is no public registration. Accounts are created from the console
 * by someone with server access.
 */
class CreateUser extends Command
{
    protected $signature = 'company-os:create-user
        {email : Email address used to log in}
        {--name= : Display name}
        {--role=founder : founder, operator or viewer}';

    protected $description = 'Create a Company OS user account (prompts for the password)';

    public function handle(AuditLogger $audit): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $password = $this->secret('Password (min 12 characters)');
        $confirmation = $this->secret('Confirm password');

        $validator = Validator::make([
            'email' => $this->argument('email'),
            'name' => $name,
            'role' => $this->option('role'),
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'email' => $this->argument('email'), 'password' => $password]);
        $user->role = UserRole::from($this->option('role'));
        $user->is_active = true;
        $user->save();

        $audit->record('user.created', $user, ['role' => $user->role->value], actorType: 'console');

        $this->info("Created {$user->role->value} account for {$user->email}.");

        return self::SUCCESS;
    }
}
