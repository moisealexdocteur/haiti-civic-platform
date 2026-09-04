<?php

namespace Tests\Unit;

use App\Services\AdminIdentityExportService;
use CodeIgniter\Test\CIUnitTestCase;

final class AdminIdentityExportServiceTest extends CIUnitTestCase
{
    public function testExportsContainOperationalFieldsWithoutSensitiveValues(): void
    {
        $rows = [[
            'public_reference' => 'DOS-7K4M-9P2R-X8CW',
            'verification_status' => 'pending',
            'department_code' => 'HT-ND',
            'document_count' => 3,
            'created_at' => '2026-09-04 10:00:00',
        ]];
        $labels = $this->labels();
        $departments = ['HT-ND' => 'Nord'];
        $exporter = new AdminIdentityExportService();

        $xls = $exporter->xls($rows, $labels, $departments);
        $pdf = $exporter->pdf($rows, $labels, $departments, 'Parti de test');

        $this->assertStringStartsWith('<?xml', $xls);
        $this->assertStringContainsString('DOS-7K4M-9P2R-X8CW', $xls);
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('DOS-7K4M-9P2R-X8CW', $pdf);
        $this->assertStringNotContainsString('1111111111', $xls . $pdf);
        $this->assertStringNotContainsString('+50900000000', $xls . $pdf);
    }

    public function testPdfPaginatesLargeExports(): void
    {
        $rows = [];

        for ($index = 0; $index < 80; $index++) {
            $rows[] = [
                'public_reference' => 'DOS-7K4M-9P2R-' . str_pad((string) $index, 4, '2'),
                'verification_status' => 'pending',
                'department_code' => 'HT-OU',
                'document_count' => 3,
                'created_at' => '2026-09-04 10:00:00',
            ];
        }

        $pdf = (new AdminIdentityExportService())->pdf(
            $rows,
            $this->labels(),
            ['HT-OU' => 'Ouest'],
            'Parti de test'
        );

        $this->assertStringContainsString('/Count 3', $pdf);
    }

    private function labels(): array
    {
        return [
            'sheet' => 'Dossiers',
            'title' => 'Liste des dossiers',
            'page' => 'Page %d sur %d',
            'reference' => 'Référence',
            'status' => 'Statut',
            'department' => 'Département',
            'documents' => 'Pièces',
            'submitted' => 'Déposé le',
            'notProvided' => 'Non fourni',
            'statuses' => ['pending' => 'En attente'],
        ];
    }
}
