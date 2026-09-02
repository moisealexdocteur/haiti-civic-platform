<?php

namespace App\Commands;

use App\Services\AdminBootstrapService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;

final class AdminBootstrap extends BaseCommand
{
    protected $group = 'Administration';
    protected $name = 'admin:bootstrap';
    protected $description = 'Crée le premier administrateur d’un tenant vide.';
    protected $usage = 'admin:bootstrap <tenant-slug> <email> <display-name>';
    protected $arguments = [
        'tenant-slug' => 'Slug du tenant actif.',
        'email' => 'Courriel du premier administrateur.',
        'display-name' => 'Nom affiché du premier administrateur.',
    ];

    public function run(array $params)
    {
        $tenantSlug = (string) ($params[0] ?? '');
        $email = (string) ($params[1] ?? '');
        $displayName = (string) ($params[2] ?? '');
        $password = getenv('ADMIN_BOOTSTRAP_PASSWORD');

        if (
            $tenantSlug === ''
            || $email === ''
            || $displayName === ''
        ) {
            throw new RuntimeException(
                'Usage: php spark admin:bootstrap <tenant-slug> <email> <display-name>'
            );
        }

        if (! is_string($password) || $password === '') {
            throw new RuntimeException(
                'ADMIN_BOOTSTRAP_PASSWORD must be provided through the process environment.'
            );
        }

        try {
            $result = (new AdminBootstrapService())
                ->bootstrapFirstAdministrator(
                    $tenantSlug,
                    $email,
                    $displayName,
                    $password
                );
        } finally {
            putenv('ADMIN_BOOTSTRAP_PASSWORD');
            $password = '';
        }

        CLI::write('Premier administrateur créé.', 'green');
        CLI::write('Tenant : ' . $result['tenant_slug']);
        CLI::write('User ID : ' . $result['user_id']);
        CLI::write('Role : ' . $result['role_code']);
    }
}
