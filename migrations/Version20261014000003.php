<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\PortableMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/**
 * Image editing: the focal point of every image, and the untouched upload of edited ones.
 */
final class Version20261014000003 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add focal point and source file to media';
    }

    public function up(Schema $schema): void
    {
        $media = $schema->getTable('media');
        // Existing images get the middle as their focal point, which is how they were shown so far.
        $media->addColumn('focal_x', Types::FLOAT, ['default' => 0.5]);
        $media->addColumn('focal_y', Types::FLOAT, ['default' => 0.5]);
        $media->addColumn('source_file', Types::STRING, ['length' => 100, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $media = $schema->getTable('media');
        $media->dropColumn('focal_x');
        $media->dropColumn('focal_y');
        $media->dropColumn('source_file');
    }
}
