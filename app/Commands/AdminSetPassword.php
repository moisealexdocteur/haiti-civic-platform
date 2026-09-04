<?php

namespace App\Commands;

use App\Services\AdminPasswordResetService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;

final class AdminSetPassword extends BaseCommand
{
    protected $group = 'Administration';
    protected $name = 'admin:password:set';
    protected $description = 'Réinitialise un mot de passe administrateur depuis la console sécurisée.';
    protected $usage = 'admin:password:set <tenant-slug> <email>';
    protected $arguments = [
        'tenant-slug' => 'Code du tenant actif.',
        'email' => 'Courriel du compte administrateur actif.',
    ];

    public function run(array $params)
    {
        $tenantSlug = (string) ($params[0] ?? '');
        $email = (string) ($params[1] ?? '');
        $password = getenv('ADMIN_RESET_PASSWORD');

        if ($tenantSlug === '' || $email === '') {
            throw new RuntimeException(
                'Usage: php spark admin:password:set <tenant-slug> <email>'
            );
        }

        if (! is_string($password) || $password === '') {
            throw new RuntimeException(
                'ADMIN_RESET_PASSWORD must be provided through the process environment.'
            );
        }

        try {
            $changed = (new AdminPasswordResetService())->resetFromConsole(
                $tenantSlug,
                $email,
                $password
            );
        } finally {
            putenv('ADMIN_RESET_PASSWORD');

            if (is_string($password) && function_exists('sodium_memzero')) {
                sodium_memzero($password);
            } else {
                $password = '';
            }
        }

        if (! $changed) {
            throw new RuntimeException(
                'No active administrator matches this tenant and email.'
            );
        }

        CLI::write('Mot de passe administrateur réinitialisé.', 'green');
        CLI::write('Tenant : ' . strtolower(trim($tenantSlug)));
        CLI::write('Courriel : ' . strtolower(trim($email)));
        CLI::write('Toutes les sessions précédentes ont été révoquées.');
    }
}
