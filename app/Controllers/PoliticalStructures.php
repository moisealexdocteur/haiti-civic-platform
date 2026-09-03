<?php

namespace App\Controllers;

use App\Services\PublicPoliticalStructureDirectory;

final class PoliticalStructures extends BaseController
{
    public function index(): string
    {
        $locale = strtolower(trim((string) $this->request->getGet('lang')));

        if (! in_array($locale, ['fr', 'ht'], true)) {
            $locale = 'fr';
        }

        $this->request->setLocale($locale);

        $directory = new PublicPoliticalStructureDirectory();

        return view('citizen_portal/political_structures', [
            'locale' => $locale,
            'structures' => $directory->all(),
            'counts' => $directory->counts(),
            'sourceUrl' => PublicPoliticalStructureDirectory::SOURCE_URL,
            'sourceReference' => PublicPoliticalStructureDirectory::SOURCE_REFERENCE,
            'approvalDate' => PublicPoliticalStructureDirectory::APPROVAL_DATE,
        ]);
    }
}
