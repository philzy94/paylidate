<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;
use RuntimeException;

/**
 * Idempotent first-run setup: Passport needs an RSA key pair and a personal access client
 * before anyone can sign up or log in.
 */
class InstallCommand extends Command
{
    protected $signature = 'paylidate:install';

    protected $description = 'Create the Passport keys and personal access client if they are missing';

    public function handle(ClientRepository $clients): int
    {
        if (! file_exists(storage_path('oauth-private.key'))) {
            $this->call('passport:keys', ['--no-interaction' => true]);
        } else {
            $this->info('Passport keys already exist.');
        }

        try {
            $clients->personalAccessClient('users');
            $this->info('Personal access client already exists.');
        } catch (RuntimeException $e) {
            $clients->createPersonalAccessGrantClient(config('app.name') . ' Personal Access Client', 'users');
            $this->info('Personal access client created.');
        }

        return self::SUCCESS;
    }
}
