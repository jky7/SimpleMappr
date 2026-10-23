<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleMappr\SocialLoginConfig;

final class SocialLoginConfigTest extends TestCase
{
    private array $environment = [];
    private const KEYS = ['SIMPLEMAPPR_URL', 'SIMPLEMAPPR_GOOGLE_CLIENT_ID', 'SIMPLEMAPPR_GOOGLE_CLIENT_SECRET',
        'SIMPLEMAPPR_GITHUB_CLIENT_ID', 'SIMPLEMAPPR_GITHUB_CLIENT_SECRET'];

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->environment[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
    }

    public function testNoProvidersDoesNotRequireAnAbsoluteUrl(): void
    {
        self::assertSame([], SocialLoginConfig::providers());
        self::assertNull(SocialLoginConfig::fingerprint('google'));
    }

    public function testOnlyConfiguredProviderIsPublicAndSecretsStayServerSide(): void
    {
        $this->configure();
        self::assertSame([['id'=>'google', 'name'=>'Google']], SocialLoginConfig::providers());
        self::assertSame('http://localhost:8080/auth/google/callback', SocialLoginConfig::provider('google')['redirect_uri']);
        $before = SocialLoginConfig::fingerprint('google');
        putenv('SIMPLEMAPPR_GOOGLE_CLIENT_SECRET=replaced-secret');
        self::assertNotSame($before, SocialLoginConfig::fingerprint('google'));
        self::assertStringNotContainsString('replaced-secret', json_encode(SocialLoginConfig::providers()));
    }

    public function testLiteralZeroIsAValueRatherThanAnUnsetCredential(): void
    {
        $this->configure();
        putenv('SIMPLEMAPPR_GOOGLE_CLIENT_ID=0');
        putenv('SIMPLEMAPPR_GOOGLE_CLIENT_SECRET=0');
        self::assertCount(1, SocialLoginConfig::providers());
    }

    #[DataProvider('invalidConfiguration')]
    public function testInvalidConfigurationFailsWithoutExposingSecrets(array $changes): void
    {
        $this->configure();
        foreach ($changes as $key => $value) {
            putenv($key.'='.$value);
        }
        try {
            SocialLoginConfig::validate();
            self::fail('Invalid social sign-in configuration was accepted.');
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString('private-secret', $error->getMessage());
        }
    }

    public static function invalidConfiguration(): array
    {
        return [
            'missing secret' => [['SIMPLEMAPPR_GOOGLE_CLIENT_SECRET'=>'']],
            'missing client' => [['SIMPLEMAPPR_GOOGLE_CLIENT_ID'=>'']],
            'missing origin' => [['SIMPLEMAPPR_URL'=>'']],
            'relative origin' => [['SIMPLEMAPPR_URL'=>'/']],
            'HTTP remote' => [['SIMPLEMAPPR_URL'=>'http://map.example']],
            'origin credentials' => [['SIMPLEMAPPR_URL'=>'https://user:password@map.example']],
            'unsafe path' => [['SIMPLEMAPPR_URL'=>'https://map.example/a/../maps']],
            'encoded path' => [['SIMPLEMAPPR_URL'=>'https://map.example/%2f/maps']],
            'double slash path' => [['SIMPLEMAPPR_URL'=>'https://map.example//maps']],
            'origin query' => [['SIMPLEMAPPR_URL'=>'https://map.example?x=y']],
            'origin fragment' => [['SIMPLEMAPPR_URL'=>'https://map.example#x']],
            'secret whitespace' => [['SIMPLEMAPPR_GOOGLE_CLIENT_SECRET'=>'private-secret with-spaces']],
            'secret too long' => [['SIMPLEMAPPR_GOOGLE_CLIENT_SECRET'=>str_repeat('a',4097)]],
        ];
    }

    public function testHostedPrefixAppliesToBothProviders(): void
    {
        $this->configure();
        putenv('SIMPLEMAPPR_URL=https://jky.io/simplemappr/');
        putenv('SIMPLEMAPPR_GITHUB_CLIENT_ID=github-client');
        putenv('SIMPLEMAPPR_GITHUB_CLIENT_SECRET=github-secret');
        self::assertSame('https://jky.io/simplemappr/auth/google/callback', SocialLoginConfig::provider('google')['redirect_uri']);
        self::assertSame('https://jky.io/simplemappr/auth/github/callback', SocialLoginConfig::provider('github')['redirect_uri']);
        self::assertSame('oauth:github:', SocialLoginConfig::provider('github')['identity_prefix']);
        self::assertSame('', SocialLoginConfig::provider('github')['scope']);
        self::assertNull(SocialLoginConfig::fingerprint('microsoft'));
    }

    private function configure(): void
    {
        putenv('SIMPLEMAPPR_URL=http://localhost:8080');
        putenv('SIMPLEMAPPR_GOOGLE_CLIENT_ID=google-client');
        putenv('SIMPLEMAPPR_GOOGLE_CLIENT_SECRET=private-secret');
    }
}
