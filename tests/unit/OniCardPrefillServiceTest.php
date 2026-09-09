<?php
namespace Tests\Unit;
use App\Services\OniCardPrefillService;
use CodeIgniter\Test\CIUnitTestCase;
final class OniCardPrefillServiceTest extends CIUnitTestCase
{
    public function testNonCardCannotPrefillNames(): void
    {
        $this->assertSame(['ninu'=>'','firstName'=>'','lastName'=>''], (new OniCardPrefillService())->fromEvidence(['card_detected'=>false,'fields'=>['firstName'=>'TEST']]));
    }
    public function testConflictingNumbersRemainEmptyWhileNamesAreSuggestions(): void
    {
        $result=(new OniCardPrefillService())->fromEvidence(['card_detected'=>true,'numbers'=>[
            ['value'=>'0000000000','confidence'=>95],['value'=>'1111111111','confidence'=>95]],
            'fields'=>['firstName'=>'TEST','lastName'=>'EXEMPLE']]);
        $this->assertSame('', $result['ninu']);
        $this->assertSame('TEST', $result['firstName']);
    }
    public function testLeadingZeroesAndAccentsArePreserved(): void
    {
        $result=(new OniCardPrefillService())->fromEvidence(['card_detected'=>true,'numbers'=>[
            ['value'=>'0000000000','confidence'=>95]],'fields'=>['firstName'=>'ÉVA','lastName'=>'TEST-EXEMPLE']]);
        $this->assertSame('0000000000', $result['ninu']);
        $this->assertSame('ÉVA', $result['firstName']);
        $this->assertArrayNotHasKey('status', $result);
    }
}
