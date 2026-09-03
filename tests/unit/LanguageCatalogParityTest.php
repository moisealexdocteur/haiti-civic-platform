<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class LanguageCatalogParityTest extends CIUnitTestCase
{
    private const CATALOGS = [
        'Admin',
        'CitizenPortal',
        'ErrorPage',
        'Validation',
    ];

    public function testFrenchAndHaitianCreoleCatalogsStayInSync(): void
    {
        foreach (self::CATALOGS as $catalog) {
            $french = $this->loadCatalog('fr', $catalog);
            $creole = $this->loadCatalog('ht', $catalog);

            $this->assertSame(
                array_keys($french),
                array_keys($creole),
                $catalog . ' must have the same ordered keys in French and Haitian Creole.'
            );

            foreach ($french as $key => $frenchText) {
                $creoleText = $creole[$key];
                $this->assertNotSame('', trim($creoleText), $catalog . '.' . $key . ' is empty in Haitian Creole.');
                $this->assertSame(
                    $this->placeholders($frenchText),
                    $this->placeholders($creoleText),
                    $catalog . '.' . $key . ' does not use the same placeholders in both languages.'
                );
            }
        }
    }

    /** @return array<string, string> */
    private function loadCatalog(string $locale, string $catalog): array
    {
        $values = require APPPATH . 'Language/' . $locale . '/' . $catalog . '.php';
        $this->assertIsArray($values);

        return $values;
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/\{\d+\}/', $text, $matches);
        $values = array_values(array_unique($matches[0]));
        sort($values);

        return $values;
    }
}
