<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Form builder: forms and what visitors sent through them.
 */
final class Version20261012000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the forms and form_submissions tables';
    }

    public function up(Schema $schema): void
    {
        $forms = $schema->createTable('forms');
        $forms->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $forms->addColumn('name', Types::STRING, ['length' => 120]);
        $forms->addColumn('fields', Types::JSON);
        $forms->addColumn('submit_label', Types::STRING, ['length' => 60]);
        $forms->addColumn('success_message', Types::STRING, ['length' => 500]);
        $forms->addColumn('notify_email', Types::STRING, ['length' => 180, 'notnull' => false]);
        $forms->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $forms->setPrimaryKey(['id']);

        $submissions = $schema->createTable('form_submissions');
        $submissions->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $submissions->addColumn('is_read', Types::BOOLEAN);
        $submissions->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $submissions->addColumn('answers', Types::JSON);
        $submissions->addColumn('page_url', Types::STRING, ['length' => 500, 'notnull' => false]);
        $submissions->addColumn('form_id', Types::INTEGER);
        $submissions->setPrimaryKey(['id']);
        $submissions->addIndex(['form_id'], 'IDX_C80AF9E65FF69B7D');
        $submissions->addIndex(['form_id', 'created_at'], 'idx_form_submissions_inbox');
        $submissions->addForeignKeyConstraint('forms', ['form_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_C80AF9E65FF69B7D');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('form_submissions');
        $schema->dropTable('forms');
    }
}
