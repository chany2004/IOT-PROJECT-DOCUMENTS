<?php
namespace App\Console\Commands;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdministrator extends Command
{
    protected $signature = 'monitor:admin {email?}';
    protected $description = 'Create an administrator interactively without putting passwords in shell history';
    public function handle(): int
    {
        $data = ['name' => $this->ask('Administrator name'), 'email' => $this->argument('email') ?? $this->ask('Email'),
            'password' => $this->secret('Password (at least 12 characters)')];
        $validator = Validator::make($data, ['name' => ['required', 'max:150'], 'email' => ['required', 'email', 'unique:users'],
            'password' => ['required', Password::min(12)]]);
        if ($validator->fails()) { $this->error($validator->errors()->first()); return self::FAILURE; }
        User::create($data + ['role' => 'administrator', 'is_active' => true]);
        $this->info('Administrator created.');
        return self::SUCCESS;
    }
}
