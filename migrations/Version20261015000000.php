<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\PortableMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/**
 * Pages form a tree: a page can have a parent page and a place among its siblings.
 * Existing pages stay on the top level, where they were.
 */
final class Version20261015000000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add parent and position to pages';
    }

    public function up(Schema $schema): void
    {
        $pages = $schema->getTable('pages');
        $pages->addColumn('parent_id', Types::INTEGER, ['notnull' => false]);
        $pages->addColumn('position', Types::INTEGER, ['default' => 0]);
        $pages->addIndex(['parent_id'], 'IDX_2074E575727ACA70');
        $pages->addForeignKeyConstraint('pages', ['parent_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_2074E575727ACA70');
    }

    public function down(Schema $schema): void
    {
        $pages = $schema->getTable('pages');
        $pages->removeForeignKey('FK_2074E575727ACA70');
        $pages->dropIndex('IDX_2074E575727ACA70');
        $pages->dropColumn('parent_id');
        $pages->dropColumn('position');
    }
}
