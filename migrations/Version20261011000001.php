<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Shared blocks: blocks maintained in one place and shown on many pages.
 */
final class Version20261011000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the shared_blocks table';
    }

    public function up(Schema $schema): void
    {
        $sharedBlocks = $schema->createTable('shared_blocks');
        $sharedBlocks->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $sharedBlocks->addColumn('name', Types::STRING, ['length' => 120]);
        $sharedBlocks->addColumn('blocks', Types::JSON);
        $sharedBlocks->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $sharedBlocks->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('shared_blocks');
    }
}
