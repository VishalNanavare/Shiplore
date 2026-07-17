<?php

declare(strict_types=1);

use App\Libraries\Geo\IndiaStates;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/Geo/IndiaStates.php';

/** GST state-code lookup used by registration (state name -> 2-digit code). */
final class IndiaStatesTest extends TestCase
{
    public function testCodeForCommonStates(): void
    {
        $this->assertSame('27', IndiaStates::codeForName('Maharashtra'));
        $this->assertSame('29', IndiaStates::codeForName('Karnataka'));
        $this->assertSame('33', IndiaStates::codeForName('Tamil Nadu'));
        $this->assertSame('24', IndiaStates::codeForName('Gujarat'));
    }

    public function testHandlesGoogleAndAltSpellings(): void
    {
        $this->assertSame('07', IndiaStates::codeForName('National Capital Territory of Delhi'));
        $this->assertSame('07', IndiaStates::codeForName('Delhi'));
        $this->assertSame('21', IndiaStates::codeForName('Orissa'));
        $this->assertSame('34', IndiaStates::codeForName('Pondicherry'));
        $this->assertSame('19', IndiaStates::codeForName('West Bengal'));
    }

    public function testUnknownReturnsEmpty(): void
    {
        $this->assertSame('', IndiaStates::codeForName('Atlantis'));
        $this->assertSame('', IndiaStates::codeForName(''));
    }

    public function testListIsCodeKeyedAndComplete(): void
    {
        $list = IndiaStates::list();
        $this->assertSame('Maharashtra', $list['27']);
        $this->assertGreaterThanOrEqual(36, count($list));
        foreach ($list as $code => $name) {
            $this->assertSame(2, strlen((string) $code));
            $this->assertNotSame('', $name);
        }
    }
}
