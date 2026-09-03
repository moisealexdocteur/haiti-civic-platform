<?php

namespace Tests\Database;

use App\Services\PublicPoliticalStructureDirectory;
use CodeIgniter\Test\CIUnitTestCase;

final class PublicPoliticalStructureDirectoryTest extends CIUnitTestCase
{
    public function testOfficialCepCatalogContainsExpected105Structures(): void
    {
        $directory = new PublicPoliticalStructureDirectory();
        $rows = $directory->all();
        $counts = $directory->counts();

        $this->assertCount(105, $rows);
        $this->assertSame(105, $counts['total']);
        $this->assertSame(88, $counts['parti']);
        $this->assertSame(17, $counts['groupement']);

        $this->assertSame(1, (int) $rows[0]['cep_list_position']);
        $this->assertSame('Konkèt pou Konkeri Kiskeya', $rows[0]['name']);
        $this->assertSame('KONKET-K2', $rows[0]['acronym']);

        $fanmiLavalas = array_values(array_filter(
            $rows,
            static fn (array $row): bool =>
                (string) $row['acronym'] === 'FANMI LAVALAS'
        ));

        $this->assertCount(1, $fanmiLavalas);
        $this->assertSame('parti', $fanmiLavalas[0]['structure_type']);

        $kapab = array_values(array_filter(
            $rows,
            static fn (array $row): bool =>
                (string) $row['acronym'] === 'KAPAB'
        ));

        $this->assertCount(1, $kapab);
        $this->assertSame('groupement', $kapab[0]['structure_type']);
        $this->assertSame(89, (int) $kapab[0]['cep_list_position']);

        $this->assertSame(105, (int) $rows[104]['cep_list_position']);
        $this->assertSame('Coppos-Haïti et Alliés', $rows[104]['name']);
        $this->assertSame('CHA', $rows[104]['acronym']);
    }
}
