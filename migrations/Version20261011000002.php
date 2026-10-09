<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Page templates saved by admins.
 */
final class Version20261011000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the page_templates table';
    }

    public function up(Schema $schema): void
    {
        $templates = $schema->createTable('page_templates');
        $templates->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $templates->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $templates->addColumn('name', Types::STRING, ['length' => 120]);
        $templates->addColumn('blocks', Types::JSON);
        $templates->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('page_templates');
    }
}
