<?php

declare(strict_types=1);

namespace Rocket\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sessions of a user revoked by the identity provider (back-channel logout)';
    }

    public function up(Schema $schema): void
    {
        // Recorded as executed (a skipped migration would be proposed again forever).
        if ($schema->getTable('user')->hasColumn('sessions_revoked_at')) {
            $this->write('The users already have the column.');

            return;
        }
        $this->addSql('ALTER TABLE "user" ADD sessions_revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP sessions_revoked_at');
    }
}
