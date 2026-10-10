<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The search index. It starts empty and is filled by the application the first time it is needed.
 */
final class Version20261013000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the search index table';
    }

    public function up(Schema $schema): void
    {
        $index = $schema->createTable('search_index');
        $index->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $index->addColumn('kind', Types::STRING, ['length' => 10]);
        $index->addColumn('content_id', Types::INTEGER);
        $index->addColumn('locale', Types::STRING, ['length' => 5]);
        $index->addColumn('title', Types::STRING, ['length' => 255]);
        $index->addColumn('body', Types::TEXT);
        $index->addColumn('title_folded', Types::STRING, ['length' => 255]);
        $index->addColumn('body_folded', Types::TEXT);
        $index->addColumn('visible', Types::BOOLEAN);
        $index->addColumn('visible_from', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $index->setPrimaryKey(['id']);
        $index->addUniqueIndex(['kind', 'content_id'], 'search_index_content');
        $index->addIndex(['visible', 'locale'], 'search_index_visible');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('search_index');
    }
}
