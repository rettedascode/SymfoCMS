<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\PortableMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/**
 * Pages and posts can choose the image that is shown when they are shared.
 */
final class Version20261013000000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add the share image to pages and posts';
    }

    public function up(Schema $schema): void
    {
        foreach (['pages' => '2074E575C45E96A8', 'posts' => '885DBAFAC45E96A8'] as $name => $hash) {
            $table = $schema->getTable($name);
            $table->addColumn('share_image_id', Types::INTEGER, ['notnull' => false]);
            $table->addIndex(['share_image_id'], 'IDX_'.$hash);
            $table->addForeignKeyConstraint('media', ['share_image_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_'.$hash);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['pages' => '2074E575C45E96A8', 'posts' => '885DBAFAC45E96A8'] as $name => $hash) {
            $table = $schema->getTable($name);
            $table->removeForeignKey('FK_'.$hash);
            $table->dropIndex('IDX_'.$hash);
            $table->dropColumn('share_image_id');
        }
    }
}
