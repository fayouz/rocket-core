<?php

declare(strict_types=1);

namespace Rocket\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Origins allowed to embed the pages of an application (iframe)';
    }

    public function up(Schema $schema): void
    {
        // Rocket Mailer had the column before rocket-core.
        $this->skipIf($schema->getTable('application')->hasColumn('allowed_origins'), 'The applications already have their origins.');
        $this->addSql('ALTER TABLE application ADD allowed_origins JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE application ALTER allowed_origins DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE application DROP allowed_origins');
    }
}
