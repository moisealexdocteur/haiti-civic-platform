<?php

namespace App\Controllers;

use App\Controllers\Concerns\PublicPage;
use App\Services\PublicPoliticalStructureDirectory;

final class PoliticalStructures extends BaseController
{
    use PublicPage;

    public function index(): string
    {
        $locale = $this->resolveLocale();

        $this->request->setLocale($locale);
        $this->rememberLocale($locale);

        $directory = new PublicPoliticalStructureDirectory();

        return view(
            'citizen_portal/political_structures',
            $this->pageData(
                $locale,
                lang('CitizenPortal.officialStructuresTitle'),
                [
                    'fr' => '/structures-politiques?lang=fr',
                    'ht' => '/structures-politiques?lang=ht',
                ],
                [
                    'wide' => true,
                    'structures' => $directory->all(),
                    'counts' => $directory->counts(),
                    'sourceUrl' => PublicPoliticalStructureDirectory::SOURCE_URL,
                    'sourceReference' => PublicPoliticalStructureDirectory::SOURCE_REFERENCE,
                    'approvalDate' => PublicPoliticalStructureDirectory::APPROVAL_DATE,
                ]
            )
        );
    }
}
