<?php
use PHPUnit\Framework\TestCase;
use SimpleMappr\OwnerLogin;

final class OwnerLoginTest extends TestCase
{
    private function withPath(?string $value): string
    {
        $previous = getenv('SIMPLEMAPPR_OWNER_LOGIN_PATH');
        $value === null ? putenv('SIMPLEMAPPR_OWNER_LOGIN_PATH') : putenv('SIMPLEMAPPR_OWNER_LOGIN_PATH='.$value);
        try { return OwnerLogin::path(); }
        finally { $previous === false ? putenv('SIMPLEMAPPR_OWNER_LOGIN_PATH') : putenv('SIMPLEMAPPR_OWNER_LOGIN_PATH='.$previous); }
    }

    public function testUnsetOrEmptyUsesTheDefault(): void
    {
        self::assertSame('/owner', $this->withPath(null));
        self::assertSame('/owner', $this->withPath(''));
    }

    public function testAConfiguredSingleSegmentIsUsed(): void
    {
        foreach (['/owner-4f9c2a1b', '/ops', '/a1b', '/' . str_repeat('x', 63)] as $path) { self::assertSame($path, $this->withPath($path)); }
    }

    public function testMalformedAndReservedPathsFailLoudlyInsteadOfFallingBack(): void
    {
        foreach (['owner', '/', '/ab', '/Owner', '/own er', '/owner/x', '/owner.php', '/-owner', '/owner_x', '/'.str_repeat('x', 64), '//owner'] as $path) {
            try { $this->withPath($path); self::fail("Accepted $path"); }
            catch (RuntimeException $e) { self::assertStringContainsString('SIMPLEMAPPR_OWNER_LOGIN_PATH', $e->getMessage(), $path); }
        }
        foreach (['/login', '/session', '/api', '/about', '/help', '/data-credits', '/usermap', '/share'] as $path) {
            try { $this->withPath($path); self::fail("Accepted reserved $path"); }
            catch (RuntimeException $e) { self::assertStringContainsString('built-in page', $e->getMessage(), $path); }
        }
    }
}
