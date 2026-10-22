<?php
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleMappr\HttpException;
use SimpleMappr\Session;
use SimpleMappr\SocialLogin;

final class SocialLoginTest extends TestCase
{
    private static OpenSSLAsymmetricKey $privateKey;
    private static array $publicKey;
    private array $environment = [];

    public static function setUpBeforeClass(): void
    {
        self::$privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $rsa = openssl_pkey_get_details(self::$privateKey)['rsa'];
        self::$publicKey = ['kty' => 'RSA', 'kid' => 'test-key', 'use' => 'sig',
            'n' => self::base64Url($rsa['n']), 'e' => self::base64Url($rsa['e'])];
    }

    protected function setUp(): void
    {
        foreach (['SIMPLEMAPPR_URL' => 'http://localhost:8080',
            'SIMPLEMAPPR_GOOGLE_CLIENT_ID' => 'google-client', 'SIMPLEMAPPR_GOOGLE_CLIENT_SECRET' => 'google-secret',
            'SIMPLEMAPPR_MICROSOFT_CLIENT_ID' => 'microsoft-client', 'SIMPLEMAPPR_MICROSOFT_CLIENT_SECRET' => 'microsoft-secret'] as $name => $value) {
            $this->environment[$name] = getenv($name);
            putenv($name.'='.$value);
        }
        Session::setSession();
        $_SESSION = ['csrf' => str_repeat('a', 64)];
        $_POST = ['_csrf' => $_SESSION['csrf']];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
        $_SESSION = [];
        $_POST = [];
    }

    public function testGoogleSuccessUsesPkceAndReturnsCanonicalIdentityOnly(): void
    {
        $authorization = [];
        $calls = [];
        $login = new SocialLogin(function (string $url, ?array $form) use (&$authorization, &$calls): array {
            $calls[] = $url;
            if ($form === null) {
                return ['keys' => [self::$publicKey]];
            }
            self::assertSame('authorization_code', $form['grant_type']);
            self::assertSame('secret-code', $form['code']);
            self::assertSame('http://localhost:8080/auth/google/callback', $form['redirect_uri']);
            self::assertSame($authorization['code_challenge'], self::base64Url(hash('sha256', $form['code_verifier'], true)));
            return ['token_type' => 'Bearer', 'id_token' => $this->token($authorization['nonce'], ['iss' => 'accounts.google.com', 'name' => "  Montréal\0 ".str_repeat('é', 130)])];
        });
        parse_str(parse_url($login->begin('google'), PHP_URL_QUERY), $authorization);
        self::assertSame('openid profile', $authorization['scope']);
        self::assertSame('S256', $authorization['code_challenge_method']);
        self::assertSame('query', $authorization['response_mode']);
        $identity = $login->complete('google', ['state' => $authorization['state'], 'code' => 'secret-code']);
        self::assertSame('google', $identity->provider);
        self::assertSame('oidc:google:'.hash('sha256', "https://accounts.google.com\0subject"), $identity->identifier);
        self::assertSame(125, mb_strlen($identity->displayName));
        self::assertStringStartsWith('Montréal ', $identity->displayName);
        self::assertSame(['https://oauth2.googleapis.com/token', 'https://www.googleapis.com/oauth2/v3/certs'], $calls);
        self::assertSame(['provider', 'identifier', 'displayName'], array_keys(get_object_vars($identity)));
        self::assertCount(0, $_SESSION['social_flows']);
    }

    public function testMicrosoftAcceptsTenantBoundSignature(): void
    {
        $tenant = '9188040d-6c67-4c5b-b112-36a304b66dad';
        [$login, $query] = $this->flow('microsoft', [
            'iss' => 'https://login.microsoftonline.com/'.$tenant.'/v2.0',
            'tid' => $tenant, 'aud' => 'microsoft-client',
        ], ['issuer' => 'https://login.microsoftonline.com/{tenantid}/v2.0']);
        $identity = $login->complete('microsoft', $query);
        self::assertSame('oidc:microsoft:'.hash('sha256', 'https://login.microsoftonline.com/'.$tenant."/v2.0\0subject"), $identity->identifier);
    }

    public function testMultipleAudiencesRequireCorrectAuthorizedParty(): void
    {
        [$login, $query] = $this->flow('google', ['aud' => ['another-client', 'google-client'], 'azp' => 'google-client']);
        self::assertSame('google', $login->complete('google', $query)->provider);
    }

    public function testBoundedClockSkewAndFallbackNameAreAccepted(): void
    {
        $originalLeeway = JWT::$leeway;
        foreach ([['iat' => time() + 30, 'nbf' => time() + 30], ['iat' => time() - 40, 'exp' => time() - 30]] as $claims) {
            [$login, $query] = $this->flow('google', $claims + ['name' => null]);
            self::assertSame('Google user', $login->complete('google', $query)->displayName);
            self::assertSame($originalLeeway, JWT::$leeway);
        }
        [$login, $query] = $this->flow('google', ['iat' => time() - 100]);
        $this->assertRefused($login, 'google', $query);
        self::assertSame($originalLeeway, JWT::$leeway);
    }

    #[DataProvider('invalidClaims')]
    public function testRejectsInvalidSignedClaims(array $claims): void
    {
        [$login, $query] = $this->flow('google', $claims);
        $this->expectException(HttpException::class);
        $login->complete('google', $query);
    }

    public static function invalidClaims(): array
    {
        return [
            'issuer' => [['iss' => 'https://attacker.example']],
            'issuer missing' => [['iss' => null]],
            'subject missing' => [['sub' => null]],
            'subject empty' => [['sub' => '']],
            'subject array' => [['sub' => ['subject']]],
            'audience' => [['aud' => 'someone-else']],
            'audience missing' => [['aud' => null]],
            'audience mixed' => [['aud' => ['google-client', 7]]],
            'authorized party' => [['azp' => 'someone-else']],
            'authorized party null' => [['azp' => null]],
            'multiple audiences missing authorized party' => [['aud' => ['google-client', 'another']]],
            'nonce' => [['nonce' => 'another-nonce']],
            'nonce missing' => [['nonce' => null]],
            'issued string' => [['iat' => (string)time()]],
            'issued float' => [['iat' => 1.25]],
            'issued missing' => [['iat' => null]],
            'issued future' => [['iat' => time() + 100]],
            'expired' => [['exp' => time() - 100]],
            'expiry missing' => [['exp' => null]],
            'expiry string' => [['exp' => (string)(time() + 300)]],
            'invalid ordering' => [['iat' => time() - 100, 'exp' => time() - 200]],
            'not before future' => [['nbf' => time() + 100]],
            'not before string' => [['nbf' => (string)(time() - 100)]],
            'not before null' => [['nbf' => null]],
            'future iat with nbf' => [['iat' => time() + 100, 'nbf' => time() - 10]],
        ];
    }

    #[DataProvider('invalidMicrosoftClaims')]
    public function testRejectsMicrosoftTenantOrKeyIssuerMismatch(array $claims, array $key): void
    {
        [$login, $query] = $this->flow('microsoft', $claims + [
            'tid' => '9188040d-6c67-4c5b-b112-36a304b66dad',
            'iss' => 'https://login.microsoftonline.com/9188040d-6c67-4c5b-b112-36a304b66dad/v2.0',
            'aud' => 'microsoft-client',
        ], $key);
        $this->expectException(HttpException::class);
        $login->complete('microsoft', $query);
    }

    public static function invalidMicrosoftClaims(): array
    {
        return [
            'no tenant' => [['tid' => null], ['issuer' => 'https://login.microsoftonline.com/{tenantid}/v2.0']],
            'malformed tenant' => [['tid' => '../common'], ['issuer' => 'https://login.microsoftonline.com/{tenantid}/v2.0']],
            'common issuer' => [['iss' => 'https://login.microsoftonline.com/common/v2.0'], ['issuer' => 'https://login.microsoftonline.com/{tenantid}/v2.0']],
            'wrong tenant issuer' => [['iss' => 'https://login.microsoftonline.com/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/v2.0'], ['issuer' => 'https://login.microsoftonline.com/{tenantid}/v2.0']],
            'no key issuer' => [[], []],
            'different key tenant' => [[], ['issuer' => 'https://login.microsoftonline.com/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/v2.0']],
            'invalid key host' => [[], ['issuer' => 'https://attacker.example/{tenantid}/v2.0']],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsUntrustedKeys(array $key): void
    {
        [$login, $query] = $this->flow('google', [], $key);
        $this->expectException(HttpException::class);
        $login->complete('google', $query);
    }

    public static function invalidKeys(): array
    {
        return [
            'unknown kid' => [['kid' => 'other']], 'hmac confusion' => [['kty' => 'oct']],
            'algorithm' => [['alg' => 'HS256']], 'encryption' => [['use' => 'enc']],
            'key operations' => [['key_ops' => ['sign']]], 'private key' => [['d' => 'AQAB']],
            'private factor' => [['p' => 'AQAB']], 'remote key' => [['x5u' => 'https://attacker.example']],
            'short modulus' => [['n' => 'AQAB']], 'bad modulus encoding' => [['n' => '!!!']],
            'huge modulus' => [['n' => str_repeat('a', 2000)]], 'bad exponent' => [['e' => 'Ag']],
        ];
    }

    #[DataProvider('invalidHeaders')]
    public function testRejectsUnsupportedJoseHeaders(array $header): void
    {
        [$login, $query] = $this->flow('google', [], [], $header);
        $this->expectException(HttpException::class);
        $login->complete('google', $query);
    }

    public static function invalidHeaders(): array
    {
        return [
            'remote jwks' => [['jku' => 'https://attacker.example']],
            'embedded key' => [['jwk' => ['kty' => 'RSA']]],
            'remote certificate' => [['x5u' => 'https://attacker.example']],
            'critical extension' => [['crit' => ['exp']]],
            'unencoded payload' => [['b64' => false]],
            'type' => [['typ' => 'something-else']],
            'missing kid' => [['kid' => '']],
        ];
    }

    public function testRejectsWrongSignatureAndDuplicateKeyIds(): void
    {
        foreach (['signature', 'duplicate'] as $failure) {
            $auth = [];
            $login = new SocialLogin(function (string $url, ?array $form) use (&$auth, $failure): array {
                if ($form === null) {
                    return ['keys' => $failure === 'duplicate' ? [self::$publicKey, self::$publicKey] : [self::$publicKey]];
                }
                $token = $this->token($auth['nonce']);
                if ($failure === 'signature') {
                    $parts = explode('.', $token);
                    $parts[2] = self::base64Url(str_repeat('x', 256));
                    $token = implode('.', $parts);
                }
                return ['token_type' => 'Bearer', 'id_token' => $token];
            });
            parse_str(parse_url($login->begin('google'), PHP_URL_QUERY), $auth);
            $this->assertRefused($login, 'google', ['state' => $auth['state'], 'code' => 'code']);
        }
    }

    #[DataProvider('invalidTokenResponses')]
    public function testRejectsInvalidTokenExchangeResponses(string $failure): void
    {
        $auth = [];
        $login = new SocialLogin(function (string $url, ?array $form) use (&$auth, $failure): array {
            if ($form === null) { return ['keys' => [self::$publicKey]]; }
            $response = ['token_type' => 'Bearer', 'id_token' => $this->token($auth['nonce'])];
            if ($failure === 'missing token') { unset($response['id_token']); }
            if ($failure === 'array token') { $response['id_token'] = ['jwt']; }
            if ($failure === 'huge token') { $response['id_token'] = str_repeat('x', 32769); }
            if ($failure === 'broken segments') { $response['id_token'] = 'abc.def'; }
            if ($failure === 'bad header json') { $response['id_token'] = 'e30.e30.e30'; }
            if ($failure === 'hmac') { $response['id_token'] = JWT::encode(['sub' => 'subject'], str_repeat('x', 64), 'HS256', 'test-key'); }
            if ($failure === 'unsigned') { $response['id_token'] = self::base64Url('{"alg":"none","kid":"test-key"}').'.e30.'; }
            if ($failure === 'wrong token type') { $response['token_type'] = 'MAC'; }
            if ($failure === 'missing token type') { unset($response['token_type']); }
            if ($failure === 'provider error') { $response['error'] = 'provider-secret-error'; }
            if ($failure === 'transport exception') { throw new RuntimeException('provider-secret-error'); }
            return $response;
        });
        parse_str(parse_url($login->begin('google'), PHP_URL_QUERY), $auth);
        $query = ['state' => $auth['state'], 'code' => 'code'];
        $this->assertRefused($login, 'google', $query);
        $this->assertRefused($login, 'google', $query);
    }

    public static function invalidTokenResponses(): array
    {
        return array_map(static fn(string $failure): array => [$failure], [
            'missing token', 'array token', 'huge token', 'broken segments', 'bad header json',
            'hmac', 'unsigned', 'wrong token type', 'missing token type', 'provider error', 'transport exception',
        ]);
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidCallbackConsumesAttemptWithoutNetwork(array $changes): void
    {
        $called = false;
        $login = new SocialLogin(function () use (&$called): array { $called = true; return []; });
        parse_str(parse_url($login->begin('google'), PHP_URL_QUERY), $auth);
        $query = array_replace(['state' => $auth['state'], 'code' => 'code'], $changes);
        $this->assertRefused($login, 'google', $query);
        self::assertFalse($called);
        $this->assertRefused($login, 'google', ['state' => $auth['state'], 'code' => 'code']);
        self::assertFalse($called);
    }

    public static function invalidQueries(): array
    {
        return [
            'provider denial' => [['error' => 'access_denied']],
            'missing code' => [['code' => null]], 'empty code' => [['code' => '']],
            'array code' => [['code' => ['code']]], 'huge code' => [['code' => str_repeat('x', 4097)]],
            'control code' => [['code' => "code\n"]], 'array extra' => [['scope' => ['openid']]],
            'too many parameters' => [array_fill_keys(range('a', 'z'), 'x')],
        ];
    }

    public function testStateIsSessionBoundAndOneUse(): void
    {
        [$login, $query] = $this->flow('google');
        $this->assertRefused($login, 'google', ['state' => str_repeat('a', 43), 'code' => 'code']);
        self::assertSame('google', $login->complete('google', $query)->provider);
        $this->assertRefused($login, 'google', $query);
        [$login, $query] = $this->flow('google');
        $_SESSION = ['csrf' => str_repeat('a', 64)];
        $this->assertRefused($login, 'google', $query);
    }

    public function testProviderMixupExpiryAndCredentialRotationInvalidateAttempt(): void
    {
        foreach (['provider', 'expiry', 'rotation', 'signed-in'] as $failure) {
            [$login, $query] = $this->flow('google');
            if ($failure === 'expiry') { $_SESSION['social_flows'][$query['state']]['created'] -= 301; }
            if ($failure === 'rotation') { putenv('SIMPLEMAPPR_GOOGLE_CLIENT_SECRET=rotated'); }
            if ($failure === 'signed-in') { $_SESSION['uid'] = 123; }
            $this->assertRefused($login, $failure === 'provider' ? 'microsoft' : 'google', $query);
            self::assertArrayNotHasKey($query['state'], $_SESSION['social_flows']);
            unset($_SESSION['uid']);
        }
    }

    public function testMissingOrMalformedStateNeverReachesProvider(): void
    {
        $called = false;
        $login = new SocialLogin(function () use (&$called): array { $called = true; return []; });
        foreach ([[], ['state' => []], ['state' => str_repeat('a', 5000)], ['state' => "\n"]] as $query) {
            $this->assertRefused($login, 'google', $query + ['code' => 'code']);
        }
        self::assertFalse($called);
    }

    public function testDisablingProviderInvalidatesPendingAttempt(): void
    {
        [$login, $query] = $this->flow('google');
        putenv('SIMPLEMAPPR_GOOGLE_CLIENT_ID');
        putenv('SIMPLEMAPPR_GOOGLE_CLIENT_SECRET');
        $this->assertRefused($login, 'google', $query);
        self::assertArrayNotHasKey($query['state'], $_SESSION['social_flows']);
    }

    public function testSubjectsAreCaseSensitiveAndIndependentOfDisplayName(): void
    {
        [$login, $query] = $this->flow('google', ['sub' => 'CaseSensitive', 'name' => 'Same name']);
        $first = $login->complete('google', $query);
        [$login, $query] = $this->flow('google', ['sub' => 'casesensitive', 'name' => 'Same name']);
        $second = $login->complete('google', $query);
        self::assertNotSame($first->identifier, $second->identifier);
        self::assertSame($first->displayName, $second->displayName);
    }

    public function testStartRequiresCsrfAndRejectsSignedInUsersOrUnsupportedProvider(): void
    {
        $login = new SocialLogin();
        $_POST['_csrf'] = 'invalid';
        try { $login->begin('google'); self::fail(); } catch (HttpException $error) { self::assertSame(403, $error->status); }
        $_POST['_csrf'] = $_SESSION['csrf'];
        $_SESSION['uid'] = 123;
        try { $login->begin('google'); self::fail(); } catch (HttpException $error) { self::assertSame(409, $error->status); }
        unset($_SESSION['uid']);
        try { $login->begin('apple'); self::fail(); } catch (HttpException $error) { self::assertSame(404, $error->status); }
    }

    public function testOnlyFiveOutstandingAttemptsSurvive(): void
    {
        $login = new SocialLogin();
        $first = [];
        parse_str(parse_url($login->begin('google'), PHP_URL_QUERY), $first);
        for ($i = 0; $i < 5; $i++) { $login->begin('google'); }
        self::assertCount(5, $_SESSION['social_flows']);
        $this->assertRefused($login, 'google', ['state' => $first['state'], 'code' => 'code']);
    }

    private function flow(string $provider, array $claims = [], array $key = [], array $header = []): array
    {
        $auth = [];
        $login = new SocialLogin(function (string $url, ?array $form) use (&$auth, $claims, $key, $header): array {
            return $form === null ? ['keys' => [array_replace(self::$publicKey, $key)]]
                : ['token_type' => 'Bearer', 'id_token' => $this->token($auth['nonce'], $claims, $header)];
        });
        parse_str(parse_url($login->begin($provider), PHP_URL_QUERY), $auth);
        return [$login, ['state' => $auth['state'], 'code' => 'code']];
    }

    private function token(string $nonce, array $claims = [], array $header = []): string
    {
        return JWT::encode(array_replace([
            'iss' => 'https://accounts.google.com', 'sub' => 'subject', 'aud' => 'google-client',
            'iat' => time() - 10, 'exp' => time() + 300, 'nonce' => $nonce, 'name' => 'Map maker',
        ], $claims), self::$privateKey, 'RS256', $header['kid'] ?? 'test-key', $header);
    }

    private function assertRefused(SocialLogin $login, string $provider, array $query): void
    {
        try {
            $login->complete($provider, $query);
            self::fail('Invalid sign-in was accepted.');
        } catch (HttpException $error) {
            self::assertSame(400, $error->status);
            self::assertStringNotContainsString('secret', $error->getMessage());
        }
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
