<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class HealthTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testHealthEndpointReturnsOk(): void
    {
        $result = $this->get('/health');

        $result->assertStatus(200);

        $result->assertJSONFragment([
            'status'   => 'ok',
            'service'  => 'haiti-civic-platform',
            'database' => 'ok',
        ]);
    }
}
