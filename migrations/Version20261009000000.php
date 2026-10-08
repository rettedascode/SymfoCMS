<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema. Written with the schema API instead of raw SQL so the same
 * migration runs on SQLite, MySQL/MariaDB and PostgreSQL.
 */
final class Version20261009000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users, pages, posts, categories, media and menu items';
    }

    public function up(Schema $schema): void
    {
        $users = $schema->createTable('users');
        $users->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $users->addColumn('email', Types::STRING, ['length' => 180]);
        $users->addColumn('name', Types::STRING, ['length' => 120]);
        $users->addColumn('password', Types::STRING, ['length' => 255]);
        $users->addColumn('role', Types::STRING, ['length' => 20]);
        $users->addColumn('active', Types::BOOLEAN);
        $users->addColumn('locale', Types::STRING, ['length' => 5]);
        $users->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $users->setPrimaryKey(['id']);
        $users->addUniqueIndex(['email'], 'UNIQ_1483A5E9E7927C74');

        $categories = $schema->createTable('categories');
        $categories->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $categories->addColumn('name', Types::STRING, ['length' => 100]);
        $categories->addColumn('slug', Types::STRING, ['length' => 100]);
        $categories->setPrimaryKey(['id']);
        $categories->addUniqueIndex(['slug'], 'UNIQ_3AF34668989D9B62');

        $media = $schema->createTable('media');
        $media->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $media->addColumn('filename', Types::STRING, ['length' => 100]);
        $media->addColumn('original_name', Types::STRING, ['length' => 255]);
        $media->addColumn('mime_type', Types::STRING, ['length' => 50]);
        $media->addColumn('size', Types::INTEGER);
        $media->addColumn('width', Types::INTEGER);
        $media->addColumn('height', Types::INTEGER);
        $media->addColumn('alt', Types::STRING, ['length' => 255]);
        $media->addColumn('variants', Types::JSON);
        $media->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $media->addColumn('uploaded_by_id', Types::INTEGER, ['notnull' => false]);
        $media->setPrimaryKey(['id']);
        $media->addUniqueIndex(['filename'], 'UNIQ_6A2CA10C3C0BE965');
        $media->addIndex(['uploaded_by_id'], 'IDX_6A2CA10CA2B28FE8');
        $media->addForeignKeyConstraint('users', ['uploaded_by_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_6A2CA10CA2B28FE8');

        $pages = $schema->createTable('pages');
        $this->addContentColumns($pages);
        $pages->addColumn('homepage', Types::BOOLEAN);
        $pages->setPrimaryKey(['id']);
        $pages->addUniqueIndex(['slug'], 'UNIQ_2074E575989D9B62');

        $posts = $schema->createTable('posts');
        $this->addContentColumns($posts);
        $posts->addColumn('excerpt', Types::TEXT, ['notnull' => false]);
        $posts->addColumn('published_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $posts->addColumn('cover_image_id', Types::INTEGER, ['notnull' => false]);
        $posts->addColumn('category_id', Types::INTEGER, ['notnull' => false]);
        $posts->addColumn('author_id', Types::INTEGER, ['notnull' => false]);
        $posts->setPrimaryKey(['id']);
        $posts->addUniqueIndex(['slug'], 'UNIQ_885DBAFA989D9B62');
        $posts->addIndex(['status', 'published_at'], 'idx_posts_listing');
        $posts->addIndex(['cover_image_id'], 'IDX_885DBAFAE5A0E336');
        $posts->addIndex(['category_id'], 'IDX_885DBAFA12469DE2');
        $posts->addIndex(['author_id'], 'IDX_885DBAFAF675F31B');
        $posts->addForeignKeyConstraint('media', ['cover_image_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_885DBAFAE5A0E336');
        $posts->addForeignKeyConstraint('categories', ['category_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_885DBAFA12469DE2');
        $posts->addForeignKeyConstraint('users', ['author_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_885DBAFAF675F31B');

        $menuItems = $schema->createTable('menu_items');
        $menuItems->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $menuItems->addColumn('location', Types::STRING, ['length' => 20]);
        $menuItems->addColumn('type', Types::STRING, ['length' => 20]);
        $menuItems->addColumn('label', Types::STRING, ['length' => 120]);
        $menuItems->addColumn('url', Types::STRING, ['length' => 500, 'notnull' => false]);
        $menuItems->addColumn('position', Types::INTEGER);
        $menuItems->addColumn('page_id', Types::INTEGER, ['notnull' => false]);
        $menuItems->addColumn('post_id', Types::INTEGER, ['notnull' => false]);
        $menuItems->addColumn('parent_id', Types::INTEGER, ['notnull' => false]);
        $menuItems->setPrimaryKey(['id']);
        $menuItems->addIndex(['location', 'position'], 'idx_menu_items_location');
        $menuItems->addIndex(['page_id'], 'IDX_70B2CA2AC4663E4');
        $menuItems->addIndex(['post_id'], 'IDX_70B2CA2A4B89032C');
        $menuItems->addIndex(['parent_id'], 'IDX_70B2CA2A727ACA70');
        $menuItems->addForeignKeyConstraint('pages', ['page_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_70B2CA2AC4663E4');
        $menuItems->addForeignKeyConstraint('posts', ['post_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_70B2CA2A4B89032C');
        $menuItems->addForeignKeyConstraint('menu_items', ['parent_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_70B2CA2A727ACA70');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('menu_items');
        $schema->dropTable('posts');
        $schema->dropTable('pages');
        $schema->dropTable('media');
        $schema->dropTable('categories');
        $schema->dropTable('users');
    }

    private function addContentColumns(\Doctrine\DBAL\Schema\Table $table): void
    {
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('title', Types::STRING, ['length' => 200]);
        $table->addColumn('slug', Types::STRING, ['length' => 200]);
        $table->addColumn('status', Types::STRING, ['length' => 20]);
        $table->addColumn('blocks', Types::JSON);
        $table->addColumn('autosave', Types::JSON, ['notnull' => false]);
        $table->addColumn('seo_title', Types::STRING, ['length' => 200, 'notnull' => false]);
        $table->addColumn('meta_description', Types::STRING, ['length' => 320, 'notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
    }
}
