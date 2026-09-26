<?php

namespace Rocket\Core\Tests\Functional;

use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Rocket\Core\Entity\Application;
use Rocket\Core\Entity\AuthenticationServer;
use Rocket\Core\Entity\User;
use Rocket\Core\Enum\UserSource;
use Rocket\Core\Event\UserLoggedOutEvent;
use Rocket\Core\Oidc\Jwt;
use Rocket\Core\Oidc\LogoutTokenValidator;
use Rocket\Core\Suite\ServiceTokenProvider;
use Rocket\Core\Suite\SuiteProvisioner;
use Rocket\Core\Tests\ApiTestTrait;
use Rocket\Core\Tests\Support\HttpMock;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

/**
 * Suite mode: back-channel logout from Rocket Auth, and calls from other bricks with access tokens of Rocket Auth.
 */
final class SuiteIdentityTest extends WebTestCase
{
    use ApiTestTrait {
        setUp as private apiSetUp;
    }

    private const ISSUER = 'https://sso.example.org';
    private const INTERNAL = 'http://sso-api';
    private const ENV = ['ROCKET_AUTH_URL', 'ROCKET_AUTH_INTERNAL_URL', 'ROCKET_AUTH_CLIENT_ID', 'ROCKET_AUTH_CLIENT_SECRET', 'ROCKET_PUBLIC_URL', 'ROCKET_INTERNAL_URL'];

    private \OpenSSLAsymmetricKey $key;
    /** @var list<array<string, mixed>> */
    private array $registrations = [];

    protected function setUp(): void
    {
        $this->apiSetUp();
        HttpMock::reset();
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        HttpMock::json(self::INTERNAL.'/.well-known/openid-configuration', [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/oauth/authorize',
            'token_endpoint' => self::ISSUER.'/oauth/token',
            'jwks_uri' => self::ISSUER.'/oauth/jwks',
            'end_session_endpoint' => self::ISSUER.'/oauth/logout',
        ]);
        HttpMock::json(self::INTERNAL.'/oauth/jwks', ['keys' => [Jwt::publicJwk($this->key)]]);
        HttpMock::json(self::INTERNAL.'/api/suite/apps', ['apps' => []]);
        HttpMock::on(self::INTERNAL.'/oauth/suite/register', function (string $method, string $url, array $options) {
            parse_str((string) $options['body'], $body);
            $this->registrations[] = ['body' => $body, 'headers' => $options['headers'] ?? []];

            return new MockResponse(json_encode(['client_id' => 'rocket-test'] + $body, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });
        $this->suite();
    }

    protected function tearDown(): void
    {
        $this->env([]);
        parent::tearDown();
    }

    public function testTheBackchannelLogoutEndpointIsDeclaredToRocketAuth(): void
    {
        $this->api('GET', '/api/suite');
        self::assertCount(1, $this->registrations);
        self::assertSame(['backchannel_logout_uri' => 'http://test-api/api/auth/oidc/backchannel-logout'], $this->registrations[0]['body']);
        self::assertContains('Authorization: Basic '.base64_encode('rocket-test:suite-secret'), $this->registrations[0]['headers']);

        // Declared once for this configuration.
        $this->api('GET', '/api/suite');
        self::assertCount(1, $this->registrations);

        // A new address is declared again.
        $this->suite(['ROCKET_INTERNAL_URL' => '']);
        $this->api('GET', '/api/suite');
        self::assertCount(2, $this->registrations);
        self::assertSame('https://test.example.org/api/auth/oidc/backchannel-logout', $this->registrations[1]['body']['backchannel_logout_uri']);
    }

    public function testBackchannelLogoutRevokesTheSessionsOfTheUser(): void
    {
        $alice = $this->linkedUser('alice@example.org', 'sub-alice');
        $bob = $this->linkedUser('bob@example.org', 'sub-bob');
        $aliceSession = 'Bearer '.$this->jwtIssuedAt($alice, time() - 30);
        $bobSession = 'Bearer '.$this->jwtIssuedAt($bob, time() - 30);
        $this->api('GET', '/api/me', authorization: $aliceSession);
        $this->assertStatus(200);

        $token = $this->logoutToken(['sub' => 'sub-alice', 'sid' => 'session-1']);
        $this->backchannel($token);
        $this->assertStatus(200);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        // Sessions opened before are refused, others' are not; a new sign-in works.
        $this->api('GET', '/api/me', authorization: $aliceSession);
        $this->assertStatus(401);
        $this->api('GET', '/api/me', authorization: $bobSession);
        $this->assertStatus(200);
        $this->api('GET', '/api/me', authorization: 'Bearer '.$this->jwtFor($this->em()->find(User::class, $alice->getId())));
        $this->assertStatus(200);

        // Each token once.
        $this->backchannel($token);
        $this->assertStatus(400);
        self::assertStringContainsString('jti', $this->client->getResponse()->getContent());

        // Unknown subject: nothing to do.
        $this->backchannel($this->logoutToken(['sub' => 'someone-else']));
        $this->assertStatus(200);
    }

    public function testALocalLogoutOnlyAnnouncesItself(): void
    {
        $alice = $this->createUser('alice@example.org');
        $session = 'Bearer '.$this->jwtFor($alice);
        $events = [];
        $this->client->disableReboot();
        static::getContainer()->get('event_dispatcher')->addListener(UserLoggedOutEvent::class, static function (UserLoggedOutEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $this->api('POST', '/api/auth/logout', authorization: $session);
        $this->assertStatus(204);
        self::assertCount(1, $events);
        self::assertSame('alice@example.org', $events[0]->user->getEmail());
        self::assertArrayHasKey('iat', $events[0]->session);

        // The other sessions of the user stay open.
        $this->api('GET', '/api/me', authorization: $session);
        $this->assertStatus(200);
        $this->api('POST', '/api/auth/logout');
        $this->assertStatus(401);
    }

    public function testInvalidLogoutTokensAreRefused(): void
    {
        $alice = $this->linkedUser('alice@example.org', 'sub-alice');
        $session = 'Bearer '.$this->jwtIssuedAt($alice, time() - 30);
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);

        $cases = [
            'signature' => $this->logoutToken(['sub' => 'sub-alice'], $other),
            'another client' => $this->logoutToken(['sub' => 'sub-alice', 'aud' => 'rocket-other']),
            'Unknown issuer' => $this->logoutToken(['sub' => 'sub-alice', 'iss' => 'https://evil.example']),
            'event' => $this->logoutToken(['sub' => 'sub-alice', 'events' => ['other' => []]]),
            'nonce' => $this->logoutToken(['sub' => 'sub-alice', 'nonce' => 'n']),
            'too old' => $this->logoutToken(['sub' => 'sub-alice', 'iat' => time() - 3600]),
            'subject' => $this->logoutToken(['sid' => 'session-1']),
            'jti' => $this->logoutToken(['sub' => 'sub-alice', 'jti' => '']),
            'Malformed' => 'not-a-jwt',
        ];
        foreach ($cases as $expected => $token) {
            $this->backchannel($token);
            $this->assertStatus(400);
            self::assertStringContainsString($expected, $this->client->getResponse()->getContent(), $expected);
        }
        $this->client->request('POST', '/api/auth/oidc/backchannel-logout');
        $this->assertStatus(400);

        $this->api('GET', '/api/me', authorization: $session);
        $this->assertStatus(200);
    }

    public function testAnotherBrickCallsWithAnAccessTokenOfRocketAuth(): void
    {
        $alice = $this->createUser('alice@example.org', ['ROLE_ADMIN']);
        $this->api('GET', '/api/suite');
        $token = 'Bearer '.$this->accessToken('rocket-cloud');

        // Not linked yet: the administrators decide who may call.
        $response = $this->api('GET', '/api/me', authorization: $token);
        $this->assertStatus(401);
        self::assertStringContainsString('rocket-cloud', $response['message']);

        [$application] = $this->createApplication(name: 'Rocket Cloud');
        $application->setOauthClientId('rocket-cloud');
        $this->em()->flush();

        $me = $this->api('GET', '/api/me', authorization: $token);
        $this->assertStatus(200);
        self::assertSame('Rocket Cloud', $me['application']['name'] ?? null, json_encode($me));
        self::assertNotNull($this->application($application->getId())->getLastUsedAt());

        // Acting as a user: never with the administrator role.
        $me = $this->api('GET', '/api/me', authorization: $token, headers: ['X-Impersonate-User' => 'alice@example.org']);
        $this->assertStatus(200);
        self::assertSame('alice@example.org', $me['user']['email']);
        self::assertNotContains('ROLE_ADMIN', $me['roles'] ?? $me['user']['roles']);

        $this->application($application->getId())->setCanImpersonate(false);
        $this->em()->flush();
        $this->api('GET', '/api/me', authorization: $token, headers: ['X-Impersonate-User' => 'alice@example.org']);
        $this->assertStatus(401);

        $this->application($application->getId())->setEnabled(false);
        $this->em()->flush();
        $this->api('GET', '/api/me', authorization: $token);
        $this->assertStatus(401);
        self::assertNotNull($alice->getId());
    }

    public function testAccessTokensAreVerified(): void
    {
        $this->api('GET', '/api/suite');
        [$application] = $this->createApplication(name: 'Rocket Cloud');
        $application->setOauthClientId('rocket-cloud');
        $this->em()->flush();
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);

        $cases = [
            'signature' => $this->accessToken('rocket-cloud', [], $other),
            'not meant for this application' => $this->accessToken('rocket-cloud', ['aud' => 'rocket-mailer']),
            'expired' => $this->accessToken('rocket-cloud', ['exp' => time() - 600]),
            // A user's access token for the calling client.
            'client credentials' => $this->accessToken('rocket-cloud', ['sub' => '0190a8e6-user']),
        ];
        foreach ($cases as $expected => $token) {
            $response = $this->api('GET', '/api/me', authorization: 'Bearer '.$token);
            $this->assertStatus(401);
            self::assertStringContainsString($expected, $response['message'], $expected);
        }

        // Another issuer: not a suite token, hence a session JWT that does not decode.
        $this->api('GET', '/api/me', authorization: 'Bearer '.$this->accessToken('rocket-cloud', ['iss' => 'https://evil.example']));
        $this->assertStatus(401);
    }

    public function testStaticTokensKeepWorking(): void
    {
        $this->api('GET', '/api/suite');
        [, $secret] = $this->createApplication();
        $this->api('GET', '/api/me', authorization: 'Bearer '.$secret);
        $this->assertStatus(200);
    }

    public function testServiceTokensAreObtainedAndCached(): void
    {
        $requests = [];
        HttpMock::on(self::INTERNAL.'/oauth/token', function (string $method, string $url, array $options) use (&$requests) {
            parse_str((string) $options['body'], $body);
            $requests[] = ['body' => $body, 'headers' => $options['headers'] ?? []];

            return new MockResponse(json_encode(['access_token' => 'at-'.\count($requests), 'token_type' => 'Bearer', 'expires_in' => 300], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });
        $tokens = static::getContainer()->get(ServiceTokenProvider::class);
        $tokens->forget('rocket-mailer');
        $tokens->forget('rocket-print');

        self::assertTrue($tokens->isAvailable());
        self::assertSame('at-1', $tokens->tokenFor('mailer'));
        self::assertSame('at-1', $tokens->tokenFor('mailer'));
        self::assertSame('at-2', $tokens->tokenForClient('rocket-print'));
        self::assertCount(2, $requests);
        self::assertSame(['grant_type' => 'client_credentials', 'audience' => 'rocket-mailer'], $requests[0]['body']);
        self::assertContains('Authorization: Basic '.base64_encode('rocket-test:suite-secret'), $requests[0]['headers']);

        $tokens->forget('rocket-mailer');
        self::assertSame('at-3', $tokens->tokenFor('mailer'));
    }

    /** Environment of the next requests (read when the kernel boots). */
    private function env(array $values): void
    {
        foreach (self::ENV as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }
        foreach ($values as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv("$name=$value");
        }
    }

    private function suite(array $extra = []): void
    {
        $this->env($extra + [
            'ROCKET_AUTH_URL' => self::ISSUER,
            'ROCKET_AUTH_INTERNAL_URL' => self::INTERNAL,
            'ROCKET_AUTH_CLIENT_SECRET' => 'suite-secret',
            'ROCKET_PUBLIC_URL' => 'https://test.example.org',
            'ROCKET_INTERNAL_URL' => 'http://test-api',
        ]);
        self::ensureKernelShutdown();
        $this->client = static::createClient();
    }

    private function application(Uuid $id): Application
    {
        $this->em()->clear();

        return $this->em()->find(Application::class, $id);
    }

    private function server(): AuthenticationServer
    {
        return static::getContainer()->get(SuiteProvisioner::class)->sync();
    }

    private function linkedUser(string $email, string $subject): User
    {
        $user = (new User())->setEmail($email)->setSource(UserSource::Oidc)->setAuthenticationServer($this->server())->setExternalId($subject);
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function jwtIssuedAt(User $user, int $issuedAt): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->createFromPayload($user, ['iat' => $issuedAt]);
    }

    private function logoutToken(array $claims, ?\OpenSSLAsymmetricKey $key = null): string
    {
        $claims += [
            'iss' => self::ISSUER,
            'aud' => 'rocket-test',
            'iat' => time(),
            'exp' => time() + 120,
            'jti' => bin2hex(random_bytes(8)),
            'events' => [LogoutTokenValidator::EVENT => new \stdClass()],
        ];

        return Jwt::sign(array_filter($claims, static fn ($value) => '' !== $value), $key ?? $this->key, Jwt::publicJwk($this->key)['kid'], 'logout+jwt');
    }

    private function accessToken(string $clientId, array $claims = [], ?\OpenSSLAsymmetricKey $key = null): string
    {
        return Jwt::sign($claims + [
            'iss' => self::ISSUER,
            'sub' => $clientId,
            'aud' => 'rocket-test',
            'azp' => $clientId,
            'client_id' => $clientId,
            'scope' => '',
            'token_use' => 'access',
            'iat' => time(),
            'exp' => time() + 300,
            'jti' => bin2hex(random_bytes(8)),
        ], $key ?? $this->key, Jwt::publicJwk($this->key)['kid']);
    }

    private function backchannel(string $token): void
    {
        $this->client->request('POST', '/api/auth/oidc/backchannel-logout', ['logout_token' => $token], server: ['HTTP_ACCEPT' => 'application/json']);
    }
}
