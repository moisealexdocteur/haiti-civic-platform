<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class CoreCatalogSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $permissions = [
            ['tenants.view',        'Voir les tenants',               'core'],
            ['tenants.manage',      'Administrer les tenants',        'core'],

            ['organizations.view',  'Voir les organisations',         'core'],
            ['organizations.manage','Administrer les organisations',  'core'],

            ['users.view',          'Voir les utilisateurs',          'core'],
            ['users.manage',        'Administrer les utilisateurs',   'core'],

            ['roles.view',          'Voir les rôles',                 'core'],
            ['roles.manage',        'Administrer les rôles',          'core'],

            ['modules.view',        'Voir les modules',               'core'],
            ['modules.manage',      'Administrer les modules',        'core'],

            ['identity.view',       'Consulter les identités citoyennes',    'identity_verification'],
            ['identity.manage',     'Administrer les identités citoyennes', 'identity_verification'],

            ['audit.view',          'Consulter le journal d’audit',   'core'],
        ];

        $permissionTable = $this->db->table('permissions');

        foreach ($permissions as [$code, $name, $domain]) {
            $exists = $permissionTable
                ->where('code', $code)
                ->countAllResults();

            if ($exists === 0) {
                $permissionTable->insert([
                    'code'       => $code,
                    'name'       => $name,
                    'domain'     => $domain,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $modules = [
            ['core',                  'Core',                    true,  true],
            ['identity_verification', 'Identity Verification',   false, false],
            ['cep_compliance',        'CEP Compliance',          false, false],
            ['sms',                   'SMS Gateway',              false, false],
            ['whatsapp',              'WhatsApp Gateway',         false, false],
            ['voting_centers',        'Voting Centers',          false, false],
            ['field_operations',      'Field Operations',        false, false],
            ['events',                'Events',                  false, false],
            ['surveys',               'Surveys',                 false, false],
            ['reporting',             'Reporting',               false, false],
            ['exports',               'Exports',                 false, false],
            ['maps',                  'Maps',                    false, false],
        ];

        $moduleTable = $this->db->table('modules');

        foreach ($modules as [$code, $name, $isCore, $defaultEnabled]) {
            $exists = $moduleTable
                ->where('code', $code)
                ->countAllResults();

            if ($exists === 0) {
                $moduleTable->insert([
                    'code'               => $code,
                    'name'               => $name,
                    'version'            => '1.0.0',
                    'is_core'            => $isCore ? 1 : 0,
                    'enabled_by_default' => $defaultEnabled ? 1 : 0,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]);
            }
        }
    }
}
