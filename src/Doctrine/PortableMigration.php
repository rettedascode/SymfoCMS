<?php

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Base class for migrations that change existing tables.
 *
 * SQLite cannot alter most things in place: Doctrine copies the rows into a new
 * table and drops the old one. With foreign keys switched on, that DROP would
 * trigger "ON DELETE CASCADE" and "SET NULL" in other tables and silently
 * destroy data (menu items of a page, the category of a post, ...). This class
 * switches foreign key enforcement off for the duration of the migration.
 *
 * SQLite ignores that switch inside a transaction, so the migration runs
 * without one there. MySQL and PostgreSQL are not affected.
 */
abstract class PortableMigration extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return !$this->isSqlite();
    }

    public function preUp(Schema $schema): void
    {
        $this->setForeignKeys(false);
    }

    public function postUp(Schema $schema): void
    {
        $this->setForeignKeys(true);
    }

    public function preDown(Schema $schema): void
    {
        $this->setForeignKeys(false);
    }

    public function postDown(Schema $schema): void
    {
        $this->setForeignKeys(true);
    }

    private function setForeignKeys(bool $enabled): void
    {
        if (!$this->isSqlite()) {
            return;
        }

        $this->connection->executeStatement('PRAGMA foreign_keys = '.($enabled ? 'ON' : 'OFF'));

        if ($enabled) {
            // Rebuilding tables must not have left dangling references behind.
            $violations = $this->connection->fetchAllAssociative('PRAGMA foreign_key_check');
            $this->abortIf([] !== $violations, 'The migration left broken foreign key references in the SQLite database.');
        }
    }

    private function isSqlite(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
    }
}
