<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Redirects from old addresses to new ones.
 */
final class Version20261012000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the redirects table';
    }

    public function up(Schema $schema): void
    {
        $redirects = $schema->createTable('redirects');
        $redirects->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $redirects->addColumn('source', Types::STRING, ['length' => 255]);
        $redirects->addColumn('target', Types::STRING, ['length' => 500]);
        $redirects->addColumn('automatic', Types::BOOLEAN);
        $redirects->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $redirects->setPrimaryKey(['id']);
        $redirects->addUniqueIndex(['source'], 'UNIQ_B7713AD55F8A7F73');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('redirects');
    }
}
