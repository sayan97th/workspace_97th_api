<?php

namespace App\Console\Commands\User;

use App\Actions\Teams\CreateTeam;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CreateAdmin extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    protected $signature = 'admin:create-admin';

    protected $description = 'Create a new admin user account';

    public function __construct(private readonly CreateTeam $createTeam)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('=== Create Admin Account ===');
        $this->newLine();

        $profile = $this->askForProfile();
        if ($profile === null) {
            return self::FAILURE;
        }

        $password = $this->askForPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Creating admin account...');

        try {
            $user = DB::transaction(function () use ($profile, $password) {
                $this->ensureAdminRoleExists();

                $user = User::create([
                    'first_name' => $profile['first_name'],
                    'last_name' => $profile['last_name'],
                    'email' => $profile['email'],
                    'password' => $password,
                ]);

                // email_verified_at is intentionally excluded from mass assignment,
                // so it must be force-filled to mark the admin as verified upfront.
                $user->forceFill(['email_verified_at' => now()])->save();

                $this->createTeam->handle($user, "{$user->full_name}'s Team", isPersonal: true);

                $user->assignRole('admin');

                return $user;
            });
        } catch (Throwable $e) {
            $this->error("Failed to create admin: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Admin account created successfully.');

        $this->table(
            ['Field', 'Value'],
            [
                ['Email', $user->email],
                ['Name', $user->full_name],
                ['Role', 'admin'],
                ['Team', $user->currentTeam?->name ?? 'Not assigned'],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Ask for and validate the admin's profile details.
     *
     * @return array{first_name: string, last_name: string, email: string}|null
     */
    private function askForProfile(): ?array
    {
        $profile = [
            'first_name' => $this->ask('First name'),
            'last_name' => $this->ask('Last name'),
            'email' => $this->ask('Email address'),
        ];

        $validator = Validator::make($profile, $this->profileRules());

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return null;
        }

        return $profile;
    }

    /**
     * Ask for and validate the admin's password.
     */
    private function askForPassword(): ?string
    {
        $password = [
            'password' => $this->secret('Password'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];

        $validator = Validator::make($password, ['password' => $this->passwordRules()]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first('password'));

            return null;
        }

        return $password['password'];
    }

    /**
     * Ensure the admin role (and its permissions) exist, seeding them if needed.
     */
    private function ensureAdminRoleExists(): void
    {
        if (Role::where('name', 'admin')->doesntExist()) {
            $this->warn('Admin role not found. Running role and permission seeder...');

            (new RolePermissionSeeder)->run();
        }
    }
}
