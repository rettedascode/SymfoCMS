<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Revision history for pages and posts.
 */
final class Version20261011000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the revisions table';
    }

    public function up(Schema $schema): void
    {
        $revisions = $schema->createTable('revisions');
        $revisions->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $revisions->addColumn('author_id', Types::INTEGER, ['notnull' => false]);
        $revisions->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $revisions->addColumn('content_type', Types::STRING, ['length' => 10]);
        $revisions->addColumn('content_id', Types::INTEGER);
        $revisions->addColumn('title', Types::STRING, ['length' => 200]);
        $revisions->addColumn('blocks', Types::JSON);
        $revisions->addColumn('fields', Types::JSON);
        $revisions->setPrimaryKey(['id']);
        $revisions->addIndex(['author_id'], 'IDX_89B12285F675F31B');
        $revisions->addIndex(['content_type', 'content_id', 'created_at'], 'idx_revisions_content');
        $revisions->addForeignKeyConstraint('users', ['author_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_89B12285F675F31B');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('revisions');
    }
}
