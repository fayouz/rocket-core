<?php

declare(strict_types=1);

namespace Rocket\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926140100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Applications linked to an OAuth client of Rocket Auth (calls between the bricks of the suite)';
    }

    public function up(Schema $schema): void
    {
        // Recorded as executed (a skipped migration would be proposed again forever).
        if ($schema->getTable('application')->hasColumn('oauth_client_id')) {
            $this->write('The applications already have the column.');

            return;
        }
        $this->addSql('ALTER TABLE application ADD oauth_client_id VARCHAR(80) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A45BDDC1DCA49ED ON application (oauth_client_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_A45BDDC1DCA49ED');
        $this->addSql('ALTER TABLE application DROP oauth_client_id');
    }
}
