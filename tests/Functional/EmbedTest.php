<?php

namespace Rocket\Core\Tests\Functional;

use Rocket\Core\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EmbedTest extends WebTestCase
{
    use ApiTestTrait;

    private function embedToken(string $appToken, string $user = 'alice@example.org'): string
    {
        $response = $this->api('POST', '/api/embed/token', authorization: 'Bearer '.$appToken, headers: ['X-Impersonate-User' => $user]);
        $this->assertStatus(201);

        return $response['token'];
    }

    public function testEmbedSessionReachesTheDeclaredEndpoints(): void
    {
        $this->createUser('alice@example.org');
        [, $appToken] = $this->createApplication();
        $embed = 'Embed '.$this->embedToken($appToken);

        $context = $this->api('GET', '/api/embed/context', authorization: $embed);
        $this->assertStatus(200);
        self::assertSame(['https://partner.example'], $context['application']['allowedOrigins']);
        self::assertSame('alice@example.org', $context['user']['email']);

        $me = $this->api('GET', '/api/me', authorization: $embed);
        $this->assertStatus(200);
        self::assertTrue($me['embed']);
        self::assertNotContains('ROLE_ADMIN', $me['roles']);

        // Declared by the brick (tests/Support/EmbedEndpoints).
        $this->api('GET', '/api/system/version', authorization: $embed);
        $this->assertStatus(200);
    }

    public function testEmbedSessionIsRestrictedToTheDeclaredEndpoints(): void
    {
        $this->createUser('alice@example.org', ['ROLE_ADMIN']);
        [, $appToken] = $this->createApplication();
        $embed = 'Embed '.$this->embedToken($appToken);

        $this->api('GET', '/api/applications', authorization: $embed);
        $this->assertStatus(403);
        $this->api('GET', '/api/users', authorization: $embed);
        $this->assertStatus(403);
        $this->api('POST', '/api/embed/token', authorization: $embed);
        $this->assertStatus(403);
    }

    public function testEmbedTokenIsNotAUserSession(): void
    {
        $this->createUser('alice@example.org');
        [, $appToken] = $this->createApplication();

        $this->api('GET', '/api/me', authorization: 'Bearer '.$this->embedToken($appToken));
        $this->assertStatus(401);
    }

    public function testDisablingTheApplicationRevokesEmbedSessions(): void
    {
        $this->createUser('alice@example.org');
        [$application, $appToken] = $this->createApplication();
        $embed = 'Embed '.$this->embedToken($appToken);

        $application->setEnabled(false);
        $this->em()->flush();

        $this->api('GET', '/api/me', authorization: $embed);
        $this->assertStatus(401);
    }

    public function testOnlyImpersonatingApplicationsMintEmbedTokens(): void
    {
        $alice = $this->createUser('alice@example.org');
        [, $appToken] = $this->createApplication(canImpersonate: false, name: 'Reader');

        $this->api('POST', '/api/embed/token', authorization: 'Bearer '.$this->jwtFor($alice));
        $this->assertStatus(403);
        $this->api('POST', '/api/embed/token', authorization: 'Bearer '.$appToken);
        $this->assertStatus(403);
    }

    public function testFramePolicyIsPublicAndOnlyForImpersonatingApps(): void
    {
        [$application] = $this->createApplication(origins: ['https://crm.example']);
        [$other] = $this->createApplication(canImpersonate: false, name: 'Other', origins: ['https://nope.example']);

        $policy = $this->api('GET', '/api/embed/frame-policy?app='.$application->getId());
        $this->assertStatus(200);
        self::assertSame(['https://crm.example'], $policy['frameAncestors']);

        self::assertSame([], $this->api('GET', '/api/embed/frame-policy?app='.$other->getId())['frameAncestors']);
        self::assertSame([], $this->api('GET', '/api/embed/frame-policy?app=garbage')['frameAncestors']);
    }

    public function testApplicationsDeclareTheirOrigins(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_ADMIN']);
        [$application] = $this->createApplication(origins: []);

        $this->api('PATCH', '/api/applications/'.$application->getId(), ['allowedOrigins' => ['https://crm.example/', 'not an origin']], 'Bearer '.$this->jwtFor($admin));
        $this->assertStatus(422);
        $updated = $this->api('PATCH', '/api/applications/'.$application->getId(), ['allowedOrigins' => ['https://crm.example/']], 'Bearer '.$this->jwtFor($admin));
        $this->assertStatus(200);
        self::assertSame(['https://crm.example'], $updated['allowedOrigins']);
    }
}
