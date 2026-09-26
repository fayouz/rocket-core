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
        // Recorded as executed (a skipped migration would be proposed again forever).
        if ($schema->getTable('user')->hasColumn('groups')) {
            $this->write('The users already have their groups.');

            return;
        }
        $this->addSql('ALTER TABLE "user" ADD groups JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP groups');
    }
}
