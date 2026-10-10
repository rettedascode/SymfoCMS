<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\PortableMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/**
 * Categories get an order of their own, arranged by dragging.
 * Existing categories all start at the same place and therefore stay in alphabetical order.
 */
final class Version20261016000000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add a position to categories';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('categories')->addColumn('position', Types::INTEGER, ['default' => 0]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('categories')->dropColumn('position');
    }
}
