<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\PortableMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/**
 * Every user arranges the widgets of the start page for themselves.
 */
final class Version20261017000000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add the dashboard layout to users';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('users')->addColumn('dashboard_layout', Types::JSON, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('users')->dropColumn('dashboard_layout');
    }
}
