<?php

declare(strict_types=1);

namespace Rocket\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Secrets vault (Rocket\Core\Secrets\SecretVault): integration keys encrypted with ROCKET_SECRETS_KEY. */
final class Version20260928000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Secrets vault';
    }

    public function up(Schema $schema): void
    {
        // Recorded as executed (a skipped migration would be proposed again forever).
        if ($schema->hasTable('secret')) {
            $this->write('The secrets vault already exists.');

            return;
        }
        $this->addSql('CREATE TABLE secret (id UUID NOT NULL, scope VARCHAR(64) DEFAULT NULL, name VARCHAR(100) NOT NULL, ciphertext TEXT NOT NULL, key_id VARCHAR(16) NOT NULL, hint VARCHAR(4) DEFAULT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX secret_scope_name ON secret (scope, name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE secret');
    }
}
