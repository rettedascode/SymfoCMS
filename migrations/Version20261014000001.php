<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\PortableMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/**
 * Review workflow: the note an editor leaves when sending content back to its author.
 */
final class Version20261014000001 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add review notes to pages and posts';
    }

    public function up(Schema $schema): void
    {
        foreach (['pages', 'posts'] as $name) {
            $table = $schema->getTable($name);
            $table->addColumn('review_note', Types::TEXT, ['notnull' => false]);
            $table->addColumn('review_note_by', Types::STRING, ['length' => 120, 'notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['pages', 'posts'] as $name) {
            $table = $schema->getTable($name);
            $table->dropColumn('review_note');
            $table->dropColumn('review_note_by');
        }
    }
}
