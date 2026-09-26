<?php

namespace Rocket\Core\Tests\Functional;

use Rocket\Core\Entity\AuthenticationServer;
use Rocket\Core\Tests\ApiTestTrait;
use Rocket\Core\Tests\Support\HttpMock;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SuiteModeTest extends WebTestCase
{
    use ApiTestTrait {
        setUp as private apiSetUp;
    }

    private const ISSUER = 'https://auth.example.org';
    private const INTERNAL = 'http://auth-api';
    private const ENV = ['ROCKET_AUTH_URL', 'ROCKET_AUTH_INTERNAL_URL', 'ROCKET_AUTH_CLIENT_ID', 'ROCKET_AUTH_CLIENT_SECRET', 'ROCKET_LOCAL_LOGIN'];

    protected function setUp(): void
    {
        $this->apiSetUp();
        HttpMock::reset();
        HttpMock::json(self::INTERNAL.'/.well-known/openid-configuration', [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/authorize',
            'token_endpoint' => self::ISSUER.'/oauth/token',
            'jwks_uri' => self::ISSUER.'/oauth/jwks',
            'end_session_endpoint' => self::ISSUER.'/logout',
        ]);
        HttpMock::json(self::INTERNAL.'/api/suite/apps', ['apps' => [
            ['id' => 'print', 'name' => 'Rocket Print', 'url' => 'https://print.example.org', 'icon' => 'i-lucide-printer'],
            ['id' => 'bad', 'name' => 'Not a web address', 'url' => 'javascript:alert(1)'],
        ], 'account' => 'https://accounts.example.org']);
    }

    protected function tearDown(): void
    {
        $this->env([]);
        parent::tearDown();
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
            'ROCKET_AUTH_URL' => self::ISSUER.'/',
            'ROCKET_AUTH_INTERNAL_URL' => self::INTERNAL,
            'ROCKET_AUTH_CLIENT_SECRET' => 'suite-secret',
        ]);
        // A fresh kernel reads the new environment.
        self::ensureKernelShutdown();
        $this->client = static::createClient();
    }

    public function testStandaloneByDefault(): void
    {
        $suite = $this->api('GET', '/api/suite');
        $this->assertStatus(200);
        self::assertSame(['mode' => 'standalone', 'app' => ['id' => 'test', 'name' => 'Rocket Test'], 'localLogin' => true, 'auth' => null, 'apps' => []], $suite);
        self::assertSame(0, $this->em()->getRepository(AuthenticationServer::class)->count(['managed' => true]));
    }

    public function testSuiteSignsInThroughRocketAuthOnly(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_ADMIN']);
        $adminJwt = 'Bearer '.$this->jwtFor($admin);
        // A provider declared by hand before joining the suite.
        $this->api('POST', '/api/authentication_servers', [
            'name' => 'Other', 'type' => 'oidc', 'enabled' => true, 'url' => 'https://other.example.org', 'clientId' => 'x', 'clientSecret' => 'y',
        ], $adminJwt);
        $this->assertStatus(201);

        $this->suite();
        $suite = $this->api('GET', '/api/suite');
        self::assertSame('suite', $suite['mode']);
        self::assertFalse($suite['localLogin']);
        self::assertSame(['name' => 'Rocket Auth', 'url' => self::ISSUER], array_intersect_key($suite['auth'], ['name' => 1, 'url' => 1]));
        self::assertSame(['Rocket Print'], array_column($suite['apps'], 'name'));
        self::assertSame(self::ISSUER.'/logout?client_id=rocket-test', $suite['auth']['logoutUrl']);
        // "Mon compte": the interface of Rocket Auth, which the issuer may not be.
        self::assertSame('https://accounts.example.org', $suite['auth']['accountUrl']);

        // The Rocket Auth server was declared from the configuration.
        $server = $this->em()->getRepository(AuthenticationServer::class)->findOneBy(['managed' => true]);
        self::assertSame((string) $server->getId(), $suite['auth']['providerId']);
        self::assertSame([self::ISSUER, self::INTERNAL, 'rocket-test', 'rocket-admins', true, true], [
            $server->getUrl(), $server->getInternalUrl(), $server->getClientId(), $server->getAdminGroupDn(), $server->isEnabled(), $server->isLinkExistingAccounts(),
        ]);
        self::assertStringContainsString('groups', $server->getScopes());

        // Only Rocket Auth on the login page; local passwords refused; no first-run setup.
        $providers = $this->api('GET', '/api/auth/providers')['providers'];
        self::assertSame(['Rocket Auth'], array_column($providers, 'name'));
        $this->api('POST', '/api/auth/login', ['email' => 'admin@example.org', 'password' => 'correct-horse-battery']);
        $this->assertStatus(401);
        self::assertStringContainsString('Rocket Auth', $this->client->getResponse()->getContent());
        self::assertFalse($this->api('GET', '/api/setup')['required']);

        // The managed server is read-only; accounts are not created here.
        $this->api('PATCH', '/api/authentication_servers/'.$server->getId(), ['name' => 'Renamed'], $adminJwt);
        $this->assertStatus(403);
        $this->api('DELETE', '/api/authentication_servers/'.$server->getId(), authorization: $adminJwt);
        $this->assertStatus(403);
        $this->api('POST', '/api/users', ['email' => 'new@example.org', 'plainPassword' => 'a-long-password-123'], $adminJwt);
        $this->assertStatus(409);

        // Same configuration: nothing rewritten (the secret is encrypted with a random nonce: rewriting would change it).
        $secret = $server->getEncryptedClientSecret();
        $this->api('GET', '/api/suite');
        $this->em()->clear();
        self::assertSame($secret, $this->em()->find(AuthenticationServer::class, $server->getId())->getEncryptedClientSecret());
    }

    public function testEmergencyLocalLogin(): void
    {
        $this->createUser('admin@example.org', ['ROLE_ADMIN']);
        $this->suite(['ROCKET_LOCAL_LOGIN' => '1']);

        self::assertTrue($this->api('GET', '/api/suite')['localLogin']);
        $response = $this->api('POST', '/api/auth/login', ['email' => 'admin@example.org', 'password' => 'correct-horse-battery']);
        $this->assertStatus(200);
        self::assertArrayHasKey('token', $response);
    }

    public function testBackToStandaloneReleasesTheServer(): void
    {
        $this->suite();
        $this->api('GET', '/api/suite');
        self::assertSame(1, $this->em()->getRepository(AuthenticationServer::class)->count(['managed' => true]));

        $this->env([]);
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        self::assertSame('standalone', $this->api('GET', '/api/suite')['mode']);
        $this->em()->clear();
        $server = $this->em()->getRepository(AuthenticationServer::class)->findOneBy(['name' => 'Rocket Auth']);
        self::assertFalse($server->isManaged());
        self::assertFalse($server->isEnabled());
    }
}
