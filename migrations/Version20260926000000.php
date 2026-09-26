<?php

declare(strict_types=1);

namespace Rocket\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suite mode: authentication servers managed by the configuration (Rocket Auth)';
    }

    public function up(Schema $schema): void
    {
        // Idempotent like the other migrations of the bundle: recorded as executed when the column exists.
        if ($schema->getTable('authentication_server')->hasColumn('managed')) {
            $this->write('The authentication servers already have the column.');

            return;
        }
        $this->addSql('ALTER TABLE authentication_server ADD managed BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE authentication_server DROP managed');
    }
}
