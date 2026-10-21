<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdministrator extends Command
{
    protected $signature = 'app:create-admin {email} {--name=} {--contacts : Permitir consulta de contactos} {--imports : Permitir carga y consulta de originales}';

    protected $description = 'Crear un administrador con contraseña solicitada de forma oculta; no publica registro de usuarios.';

    public function handle(): int
    {
        $data = ['email' => strtolower($this->argument('email')), 'name' => $this->option('name') ?: $this->ask('Nombre'), 'password' => $this->secret('Contraseña (mínimo 12 caracteres)')];
        $v = Validator::make($data, ['email' => 'required|email|unique:users,email', 'name' => 'required|string|max:255', 'password' => 'required|string|min:12']);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user = new User;
        $user->forceFill($data + ['is_admin' => true, 'can_view_contacts' => (bool) $this->option('contacts'), 'can_import_files' => (bool) $this->option('imports')])->save();
        $this->info('Administrador creado.');

        return self::SUCCESS;
    }
}
