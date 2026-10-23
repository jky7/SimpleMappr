<?php
use PHPUnit\Framework\TestCase;
use SimpleMappr\Mappr\Mappr;

final class ZoomTest extends TestCase
{
    private function render(array $controls = []): array
    {
        $map = new class($controls) extends Mappr {
            public function __construct(private array $controls)
            {
                parent::__construct();
            }

            public function getRequest()
            {
                return $this->controls + [
                    'width' => 600,
                    'height' => 300,
                    'bbox_map' => '-100,30,-60,50',
                    'layers' => ['countries' => 'on'],
                ];
            }

            public function createOutput()
            {
                $extent = $this->map_obj->extent;
                return [
                    'png' => $this->image->getBytes(),
                    'extent' => [$extent->minx, $extent->miny, $extent->maxx, $extent->maxy],
                ];
            }
        };
        $output = $map->execute()->createOutput();
        self::assertSame("\x89PNG\r\n\x1a\n", substr($output['png'], 0, 8));
        $image = imagecreatefromstring($output['png']);
        self::assertNotFalse($image);
        self::assertSame([600, 300], [imagesx($image), imagesy($image)]);
        imagedestroy($image);
        return $output['extent'];
    }

    private function assertExtent(array $expected, array $actual): void
    {
        foreach ($expected as $index => $coordinate) {
            self::assertEqualsWithDelta($coordinate, $actual[$index], 0.1);
        }
    }

    public function testBoxZoomRendersTheSelectedGeographicArea(): void
    {
        $this->assertExtent([-90, 38, -70, 48], $this->render(['bbox_rubberband' => '150,30,450,180']));
    }

    public function testPointZoomCentersOnTheSelectedLocation(): void
    {
        $this->assertExtent([-100, 40, -80, 50], $this->render(['bbox_rubberband' => '150,75,150,75']));
    }

    public function testCenteredZoomInAndOutPreserveTheViewport(): void
    {
        $this->assertExtent([-90, 35, -70, 45], $this->render(['zoom_in' => true]));
        $this->assertExtent([-120, 20, -40, 60], $this->render(['zoom_out' => true]));
        $this->assertExtent([-100, 30, -60, 50], $this->render(['zoom_in' => true, 'zoom_out' => true]));
    }
}
