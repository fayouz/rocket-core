<?php

declare(strict_types=1);

namespace Rocket\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Groups of the users (directory, OpenID Connect provider, or managed by hand)';
    }

    public function up(Schema $schema): void
    {
        // Rocket Auth had the column before rocket-core.
        $this->skipIf($schema->getTable('user')->hasColumn('groups'), 'The users already have their groups.');
        $this->addSql('ALTER TABLE "user" ADD groups JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP groups');
    }
}
