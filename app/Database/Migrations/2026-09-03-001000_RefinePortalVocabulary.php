<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class RefinePortalVocabulary extends Migration
{
    public function up()
    {
        $this->db->table('tenants')
            ->where('slug', 'demo-citoyen')
            ->where('name', 'Organisation de démonstration')
            ->update([
                'name' => 'Parti ou groupement de démonstration',
            ]);
    }

    public function down()
    {
        $this->db->table('tenants')
            ->where('slug', 'demo-citoyen')
            ->where('name', 'Parti ou groupement de démonstration')
            ->update([
                'name' => 'Organisation de démonstration',
            ]);
    }
}
