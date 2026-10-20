<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleMappr\Utility;

final class CoordinateTest extends TestCase
{
    public static function coordinates(): array
    {
        return [
            'decimal' => ['43.6532,-79.3832', [43.6532, -79.3832]],
            'prime meridian' => ['45,0', [45.0, 0.0]],
            'equator' => ['0,-75', [0.0, -75.0]],
            'origin' => ['0,0', [0.0, 0.0]],
            'DMS' => ['43°39\'11.5"N,79°22\'59.5"W', [43.6531944444, -79.3831944444]],
            'reversed DMS' => ['79°22\'59.5"W,43°39\'11.5"N', [43.6531944444, -79.3831944444]],
        ];
    }

    #[DataProvider('coordinates')]
    public function testCoordinatesKeepLatitudeLongitudeOrder(string $input, array $expected): void
    {
        $actual = Utility::makeCoordinates($input);
        self::assertEqualsWithDelta($expected[0], (float)$actual[0], 0.0000001);
        self::assertEqualsWithDelta($expected[1], (float)$actual[1], 0.0000001);
        self::assertTrue(Utility::onEarth((object)['x' => $actual[1], 'y' => $actual[0]]));
    }

    public function testInvalidCoordinateDoesNotBecomeOrigin(): void
    {
        self::assertSame([null, null], Utility::makeCoordinates('invalid'));
        self::assertFalse(Utility::onEarth((object)['x' => null, 'y' => null]));
        self::assertFalse(Utility::onEarth((object)['x' => 181, 'y' => 0]));
    }
}
