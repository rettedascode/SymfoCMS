<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The activity log.
 */
final class Version20261014000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the audit log table';
    }

    public function up(Schema $schema): void
    {
        $log = $schema->createTable('audit_log');
        $log->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $log->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $log->addColumn('user_id', Types::INTEGER, ['notnull' => false]);
        $log->addColumn('user_name', Types::STRING, ['length' => 120]);
        $log->addColumn('action', Types::STRING, ['length' => 40]);
        $log->addColumn('subject_type', Types::STRING, ['length' => 30]);
        $log->addColumn('subject_id', Types::INTEGER, ['notnull' => false]);
        $log->addColumn('subject_label', Types::STRING, ['length' => 255]);
        $log->addColumn('details', Types::JSON, ['notnull' => false]);
        $log->setPrimaryKey(['id']);
        $log->addIndex(['created_at'], 'audit_log_created');
        $log->addIndex(['user_id'], 'audit_log_user');
        $log->addIndex(['subject_type', 'subject_id'], 'audit_log_subject');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('audit_log');
    }
}
