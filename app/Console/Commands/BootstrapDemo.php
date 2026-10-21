<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class BootstrapDemo extends Command
{
    protected $signature = 'app:bootstrap-demo';

    protected $description = 'Create the first demo administrator from private deployment variables; never replace an account.';

    public function handle(): int
    {
        if (User::where('is_admin', true)->exists()) {
            $this->info('An administrator already exists; credentials unchanged.');
            return self::SUCCESS;
        }
        $data = ['email' => strtolower(getenv('BOOTSTRAP_ADMIN_EMAIL') ?: ''), 'password' => getenv('BOOTSTRAP_ADMIN_PASSWORD') ?: ''];
        $validator = Validator::make($data, ['email' => 'required|email|unique:users,email', 'password' => 'required|string|min:12']);
        if ($validator->fails()) {
            $this->error('Set BOOTSTRAP_ADMIN_EMAIL and a password of at least 12 characters in the private service environment.');
            return self::FAILURE;
        }
        $user = new User;
        $user->forceFill($data + ['name' => 'Administrador ACREDI', 'is_admin' => true, 'can_import_files' => true, 'can_view_contacts' => false])->save();
        $this->info('First administrator created. Remove the bootstrap variables from the deployment environment.');
        return self::SUCCESS;
    }
}
