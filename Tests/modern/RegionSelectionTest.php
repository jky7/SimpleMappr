<?php

use PHPUnit\Framework\TestCase;
use SimpleMappr\Mappr\Application\Query;

final class RegionSelectionTest extends TestCase
{
    private function select(string $pixels, string $layer = 'countries'): array
    {
        $previous = $_REQUEST;
        $_REQUEST = ['bbox_query' => $pixels, 'qlayer' => $layer, 'width' => 900, 'height' => 450];
        try {
            return (new Query())->execute()->queryLayer()->data;
        } finally {
            $_REQUEST = $previous;
        }
    }

    public function testClickSelectsTheCountryUnderThePointer(): void
    {
        self::assertSame(['Brazil'], $this->select('315,267,315,267'));
    }

    public function testClickingOceanSelectsNothing(): void
    {
        self::assertSame([], $this->select('10,225,10,225'));
    }

    public function testClickCanSelectAProvince(): void
    {
        self::assertSame(['CAN[ON]'], $this->select('251.55,115.87,251.55,115.87', 'stateprovinces_polygon'));
    }

    public function testBoxSelectionStillFindsCountries(): void
    {
        self::assertContains('Brazil', $this->select('310,260,330,280'));
    }

    public function testReversedSelectionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->select('330,280,310,260');
    }
}
