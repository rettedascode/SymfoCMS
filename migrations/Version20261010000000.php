<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\PortableMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/**
 * Multilingual content, step 1: every page, post, category and menu item gets
 * a language, pages and posts get a translation group, and settings get a table.
 *
 * Existing content is assigned to the site language (APP_LOCALE) and keeps its addresses.
 */
final class Version20261010000000 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Add locales and translation groups to content, add the settings table';
    }

    public function up(Schema $schema): void
    {
        $settings = $schema->createTable('settings');
        $settings->addColumn('name', Types::STRING, ['length' => 100]);
        $settings->addColumn('value', Types::JSON);
        $settings->setPrimaryKey(['name']);

        // Addresses only have to be unique within one language from now on.
        foreach (['pages' => 'UNIQ_2074E575989D9B62', 'posts' => 'UNIQ_885DBAFA989D9B62'] as $name => $slugIndex) {
            $table = $schema->getTable($name);
            $table->addColumn('locale', Types::STRING, ['length' => 10, 'default' => 'en']);
            $table->addColumn('translation_group', Types::STRING, ['length' => 32, 'default' => '']);
            $table->dropIndex($slugIndex);
            $table->addUniqueIndex(['locale', 'slug'], 'uniq_'.$name.'_locale_slug');
        }

        $categories = $schema->getTable('categories');
        $categories->addColumn('locale', Types::STRING, ['length' => 10, 'default' => 'en']);
        $categories->dropIndex('UNIQ_3AF34668989D9B62');
        $categories->addUniqueIndex(['locale', 'slug'], 'uniq_categories_locale_slug');

        $menuItems = $schema->getTable('menu_items');
        $menuItems->addColumn('locale', Types::STRING, ['length' => 10, 'default' => 'en']);
        $menuItems->dropIndex('idx_menu_items_location');
        $menuItems->addIndex(['location', 'locale', 'position'], 'idx_menu_items_location');
    }

    public function postUp(Schema $schema): void
    {
        parent::postUp($schema);

        $locale = $_SERVER['APP_LOCALE'] ?? $_ENV['APP_LOCALE'] ?? 'en';
        $locale = \is_string($locale) && 1 === preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $locale) ? $locale : 'en';

        foreach (['pages', 'posts', 'categories', 'menu_items'] as $table) {
            $this->connection->executeStatement('UPDATE '.$table.' SET locale = ?', [$locale]);
        }

        // Every existing page and post starts as the only member of its own translation group.
        foreach (['pages', 'posts'] as $table) {
            foreach ($this->connection->fetchFirstColumn('SELECT id FROM '.$table) as $id) {
                $this->connection->executeStatement('UPDATE '.$table.' SET translation_group = ? WHERE id = ?', [bin2hex(random_bytes(16)), $id]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('settings');

        foreach (['pages' => 'UNIQ_2074E575989D9B62', 'posts' => 'UNIQ_885DBAFA989D9B62'] as $name => $slugIndex) {
            $table = $schema->getTable($name);
            $table->dropIndex('uniq_'.$name.'_locale_slug');
            $table->dropColumn('locale');
            $table->dropColumn('translation_group');
            $table->addUniqueIndex(['slug'], $slugIndex);
        }

        $categories = $schema->getTable('categories');
        $categories->dropIndex('uniq_categories_locale_slug');
        $categories->dropColumn('locale');
        $categories->addUniqueIndex(['slug'], 'UNIQ_3AF34668989D9B62');

        $menuItems = $schema->getTable('menu_items');
        $menuItems->dropIndex('idx_menu_items_location');
        $menuItems->dropColumn('locale');
        $menuItems->addIndex(['location', 'position'], 'idx_menu_items_location');
    }
}
