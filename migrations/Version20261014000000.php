<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\PortableMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/**
 * Two-step login: the secret of the authenticator app and the backup codes of every user.
 */
final class Version20261014000000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add two-step login to users';
    }

    public function up(Schema $schema): void
    {
        $users = $schema->getTable('users');
        $users->addColumn('totp_secret', Types::STRING, ['length' => 64, 'notnull' => false]);
        $users->addColumn('totp_last_step', Types::INTEGER, ['notnull' => false]);
        $users->addColumn('backup_codes', Types::JSON, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $users = $schema->getTable('users');
        $users->dropColumn('totp_secret');
        $users->dropColumn('totp_last_step');
        $users->dropColumn('backup_codes');
    }
}
