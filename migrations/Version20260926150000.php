<?php

declare(strict_types=1);

namespace Rocket\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Index names of the authentication servers aligned with the mapping where the tables predate rocket-core (Rocket
 * Mailer's hand-written migrations created IDX_AUTHENTICATION_SERVER_TYPE, not mapped, and named the index of
 * user.authentication_server_id IDX_8F1A7E8F4C1E9A2). Nothing to do elsewhere.
 */
final class Version20260926150000 extends AbstractMigration
{
    private const LEGACY_USER_INDEX = 'idx_8f1a7e8f4c1e9a2';
    private const USER_INDEX = 'IDX_8D93D649C2F36C49';
    private const LEGACY_TYPE_INDEX = 'idx_authentication_server_type';

    public function getDescription(): string
    {
        return 'Authentication servers: index names matching the mapping (installations older than rocket-core)';
    }

    public function up(Schema $schema): void
    {
        $user = $schema->hasTable('user') ? $schema->getTable('user') : null;
        $servers = $schema->hasTable('authentication_server') ? $schema->getTable('authentication_server') : null;
        $renameUserIndex = null !== $user && $user->hasIndex(self::LEGACY_USER_INDEX);
        $dropTypeIndex = null !== $servers && $servers->hasIndex(self::LEGACY_TYPE_INDEX);

        // Recorded as executed (a skipped migration would be proposed again forever).
        if (!$renameUserIndex && !$dropTypeIndex) {
            $this->write('The indexes already match the mapping.');

            return;
        }
        if ($renameUserIndex) {
            // Both names on the same column: the mapped one is kept.
            $this->addSql($user->hasIndex(self::USER_INDEX)
                ? 'DROP INDEX '.self::LEGACY_USER_INDEX
                : 'ALTER INDEX '.self::LEGACY_USER_INDEX.' RENAME TO '.self::USER_INDEX);
        }
        if ($dropTypeIndex) {
            $this->addSql('DROP INDEX '.self::LEGACY_TYPE_INDEX);
        }
    }

    public function down(Schema $schema): void
    {
        // The legacy names are not restored: the mapping never declared them.
    }
}
