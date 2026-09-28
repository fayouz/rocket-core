<?php

namespace Rocket\Core\Tests\Functional;

use Psr\Log\NullLogger;
use Rocket\Core\Entity\Secret;
use Rocket\Core\Repository\SecretRepository;
use Rocket\Core\Secrets\SecretNotFoundException;
use Rocket\Core\Secrets\SecretsException;
use Rocket\Core\Secrets\SecretsKeyMissingException;
use Rocket\Core\Secrets\SecretsKeyring;
use Rocket\Core\Secrets\SecretsProbe;
use Rocket\Core\Secrets\SecretVault;
use Rocket\Core\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application as ConsoleApplication;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\CommandTester;

final class SecretVaultTest extends WebTestCase
{
    use ApiTestTrait;

    private function vault(?SecretsKeyring $keyring = null): SecretVault
    {
        if (null === $keyring) {
            return static::getContainer()->get(SecretVault::class);
        }

        return new SecretVault(static::getContainer()->get(SecretRepository::class), $this->em(), $keyring, new NativeClock(), new NullLogger());
    }

    private function admin(): string
    {
        return 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
    }

    private function storedCiphertext(string $name): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT ciphertext FROM secret WHERE name = ?', [$name]);
    }

    public function testValuesAreEncryptedAndReadBack(): void
    {
        $vault = $this->vault();
        $secret = $vault->set('lodgify.api_key', 'lodgify-live-key-123456');

        self::assertStringNotContainsString('lodgify-live-key', $this->storedCiphertext('lodgify.api_key'));
        self::assertSame('••••3456', $secret->getMasked());
        self::assertTrue($vault->has('lodgify.api_key'));
        self::assertFalse($vault->has('lodgify.api_key', 'account-1'));

        $this->em()->clear();
        self::assertSame('lodgify-live-key-123456', $this->vault(new SecretsKeyring($_SERVER['ROCKET_SECRETS_KEY'] ?? $_ENV['ROCKET_SECRETS_KEY']))->get('lodgify.api_key'));
        self::assertNotNull($this->em()->getConnection()->fetchOne('SELECT last_used_at FROM secret'));

        // Short values: no hint.
        self::assertSame('••••••••', $vault->set('pin', '1234')->getMasked());

        // Same name, other scope: another secret.
        $vault->set('lodgify.api_key', 'account-key-abcdefgh', 'account-1');
        self::assertSame('account-key-abcdefgh', $vault->get('lodgify.api_key', 'account-1'));
        self::assertCount(2, $vault->list());
        self::assertCount(3, $vault->list(false));

        self::assertTrue($vault->delete('pin'));
        self::assertFalse($vault->delete('pin'));
        $this->expectException(SecretNotFoundException::class);
        $vault->get('pin');
    }

    public function testCiphertextIsBoundToItsSecret(): void
    {
        $vault = $this->vault();
        $vault->set('a', 'value-of-a');
        $vault->set('b', 'value-of-b');
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE secret SET ciphertext = (SELECT ciphertext FROM secret WHERE name = 'a') WHERE name = 'b'");
        $this->em()->clear();

        $this->expectException(SecretsException::class);
        $this->vault(new SecretsKeyring($_SERVER['ROCKET_SECRETS_KEY'] ?? $_ENV['ROCKET_SECRETS_KEY']))->get('b');
    }

    public function testNothingIsStoredWithoutKey(): void
    {
        $vault = $this->vault(new SecretsKeyring(''));
        self::assertFalse($vault->isConfigured());
        self::assertStringContainsString('ROCKET_SECRETS_KEY', (string) $vault->configurationError());
        self::assertFalse((new SecretsKeyring('not-base64-32-bytes'))->isConfigured());

        try {
            $vault->set('nuki.api_token', 'plain-token');
            self::fail('Stored without key.');
        } catch (SecretsKeyMissingException) {
        }
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM secret'));
    }

    public function testRotation(): void
    {
        $old = SecretsKeyring::generateKey();
        $new = SecretsKeyring::generateKey();
        $this->vault(new SecretsKeyring($old))->set('nuki.api_token', 'nuki-token-0000001');
        $this->em()->clear();

        // New key only: the secret cannot be read, and the probe says so.
        $newOnly = new SecretsKeyring($new);
        $this->expectExceptionOnGet($this->vault($newOnly), 'nuki.api_token', 'unknown key');
        $probe = new SecretsProbe($newOnly, $this->vault($newOnly), static::getContainer()->get(SecretRepository::class));
        $targets = iterator_to_array($probe->targets());
        self::assertArrayHasKey('vault', $targets);
        try {
            $targets['vault']['check']();
            self::fail('The probe should fail.');
        } catch (SecretsException $e) {
            self::assertStringContainsString('rocket:secrets:rotate', $e->getMessage());
        }

        // New key + previous: readable, then re-encrypted with the new one.
        $both = new SecretsKeyring($new, $old);
        $report = $this->vault($both)->rotate();
        self::assertSame(1, $report['rotated']);
        $this->em()->clear();
        self::assertSame('nuki-token-0000001', $this->vault($newOnly)->get('nuki.api_token'));
        self::assertSame(0, $this->vault($both)->rotate()['rotated']);
    }

    public function testGetOrEnvFallsBackToTheEnvironment(): void
    {
        $vault = $this->vault();
        $_SERVER['CONNECTOR_TEST_TOKEN'] = 'from-env';
        try {
            self::assertSame('from-env', $vault->getOrEnv('connector_test_token', 'CONNECTOR_TEST_TOKEN'));
            $vault->set('connector_test_token', 'from-vault');
            self::assertSame('from-vault', $vault->getOrEnv('connector_test_token', 'CONNECTOR_TEST_TOKEN'));
            self::assertNull($vault->getOrEnv('nothing', 'NOT_DEFINED_ANYWHERE'));
        } finally {
            unset($_SERVER['CONNECTOR_TEST_TOKEN']);
        }
    }

    public function testImportEnvCommand(): void
    {
        $_SERVER['CONNECTOR_HOMEY_TOKEN'] = 'homey-token-123456';
        $_SERVER['CONNECTOR_EMPTY'] = '';
        try {
            $vault = $this->vault();
            $vault->set('connector_existing', 'kept-value');
            $_SERVER['CONNECTOR_EXISTING'] = 'env-value';
            $tester = new CommandTester((new ConsoleApplication(static::$kernel))->find('rocket:secrets:import-env'));

            $tester->execute(['prefix' => 'CONNECTOR_', '--dry-run' => true]);
            $tester->assertCommandIsSuccessful();
            self::assertFalse($vault->has('connector_homey_token'));

            $tester->execute(['prefix' => 'CONNECTOR_']);
            $tester->assertCommandIsSuccessful();
            self::assertSame('homey-token-123456', $vault->get('connector_homey_token'));
            self::assertSame('kept-value', $vault->get('connector_existing'));
            self::assertFalse($vault->has('connector_empty'));
            self::assertStringNotContainsString('homey-token', $tester->getDisplay());

            $tester->execute(['prefix' => 'CONNECTOR_EXISTING', '--overwrite' => true]);
            $this->em()->clear();
            self::assertSame('env-value', $this->vault(new SecretsKeyring($_SERVER['ROCKET_SECRETS_KEY'] ?? $_ENV['ROCKET_SECRETS_KEY']))->get('connector_existing'));
        } finally {
            unset($_SERVER['CONNECTOR_HOMEY_TOKEN'], $_SERVER['CONNECTOR_EMPTY'], $_SERVER['CONNECTOR_EXISTING']);
        }
    }

    public function testAdminApiNeverReturnsValues(): void
    {
        $admin = $this->admin();

        $list = $this->api('GET', '/api/secrets', authorization: $admin);
        $this->assertStatus(200);
        self::assertTrue($list['configured']);
        self::assertSame([], $list['secrets']);

        $created = $this->api('POST', '/api/secrets', ['name' => 'lodgify.api_key', 'value' => 'lodgify-secret-987654'], $admin);
        $this->assertStatus(201);
        self::assertSame('••••7654', $created['masked']);
        self::assertSame('admin@example.org', $created['createdBy']);
        self::assertStringNotContainsString('lodgify-secret', (string) $this->client->getResponse()->getContent());

        $this->api('POST', '/api/secrets', ['name' => 'lodgify.api_key', 'value' => 'again'], $admin);
        $this->assertStatus(409);
        $this->api('POST', '/api/secrets', ['name' => 'bad name!', 'value' => 'x'], $admin);
        $this->assertStatus(422);
        $this->api('POST', '/api/secrets', ['name' => 'empty'], $admin);
        $this->assertStatus(422);

        $updated = $this->api('PUT', '/api/secrets/'.$created['id'], ['value' => 'lodgify-secret-111122'], $admin);
        $this->assertStatus(200);
        self::assertSame('••••1122', $updated['masked']);
        self::assertSame('lodgify-secret-111122', $this->vault()->get('lodgify.api_key'));

        $list = $this->api('GET', '/api/secrets', authorization: $admin);
        self::assertSame(['lodgify.api_key'], array_column($list['secrets'], 'name'));
        self::assertStringNotContainsString('lodgify-secret', (string) $this->client->getResponse()->getContent());

        $this->api('DELETE', '/api/secrets/'.$created['id'], authorization: $admin);
        $this->assertStatus(204);
        self::assertSame(0, $this->em()->getRepository(Secret::class)->count([]));
    }

    public function testApiIsForAdministratorsOnly(): void
    {
        $user = 'Bearer '.$this->jwtFor($this->createUser('user@example.org'));
        $this->api('GET', '/api/secrets', authorization: $user);
        $this->assertStatus(403);
        $this->api('POST', '/api/secrets', ['name' => 'x', 'value' => 'y'], $user);
        $this->assertStatus(403);
        $this->api('GET', '/api/secrets');
        $this->assertStatus(401);
    }

    public function testApiReportsAMissingKey(): void
    {
        $admin = $this->admin();
        $this->client->disableReboot();
        static::getContainer()->set(SecretsKeyring::class, new SecretsKeyring(''));

        $list = $this->api('GET', '/api/secrets', authorization: $admin);
        self::assertFalse($list['configured']);
        self::assertStringContainsString('ROCKET_SECRETS_KEY', $list['error']);
        $this->api('POST', '/api/secrets', ['name' => 'nuki.api_token', 'value' => 'plain'], $admin);
        $this->assertStatus(503);
    }

    private function expectExceptionOnGet(SecretVault $vault, string $name, string $message): void
    {
        try {
            $vault->get($name);
            self::fail('The secret should not be readable.');
        } catch (SecretsException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
