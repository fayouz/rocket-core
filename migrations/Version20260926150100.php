<?php

declare(strict_types=1);

namespace Rocket\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Color palettes: the project's one and one per application. Each statement is skipped where it already applies
 * (Rocket Mailer created the same schema before rocket-core 0.2).
 */
final class Version20260926150100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Color palettes: the project one and one per application';
    }

    public function up(Schema $schema): void
    {
        $application = $schema->getTable('application');
        $hasTable = $schema->hasTable('color_palette');
        $hasColumn = $application->hasColumn('palette_id');
        $hasForeignKey = $application->hasForeignKey('FK_A45BDDC1908BC74');
        $hasIndex = $application->hasIndex('IDX_A45BDDC1908BC74');

        // Recorded as executed (a skipped migration would be proposed again forever).
        if ($hasTable && $hasColumn && $hasForeignKey && $hasIndex) {
            $this->write('The color palettes already exist.');

            return;
        }
        if (!$hasTable) {
            $this->addSql('CREATE TABLE color_palette (id UUID NOT NULL, name VARCHAR(80) NOT NULL, primary_color VARCHAR(7) NOT NULL, secondary VARCHAR(7) DEFAULT NULL, success VARCHAR(7) DEFAULT NULL, info VARCHAR(7) DEFAULT NULL, warning VARCHAR(7) DEFAULT NULL, error VARCHAR(7) DEFAULT NULL, neutral VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY (id))');
        }
        if (!$hasColumn) {
            $this->addSql('ALTER TABLE application ADD palette_id UUID DEFAULT NULL');
        }
        if (!$hasForeignKey) {
            $this->addSql('ALTER TABLE application ADD CONSTRAINT FK_A45BDDC1908BC74 FOREIGN KEY (palette_id) REFERENCES color_palette (id) ON DELETE SET NULL NOT DEFERRABLE');
        }
        if (!$hasIndex) {
            $this->addSql('CREATE INDEX IDX_A45BDDC1908BC74 ON application (palette_id)');
        }
    }

    public function down(Schema $schema): void
    {
        // The foreign key first: the table cannot be dropped while application references it.
        $this->addSql('ALTER TABLE application DROP CONSTRAINT FK_A45BDDC1908BC74');
        $this->addSql('DROP INDEX IDX_A45BDDC1908BC74');
        $this->addSql('ALTER TABLE application DROP palette_id');
        $this->addSql('DROP TABLE color_palette');
    }
}
