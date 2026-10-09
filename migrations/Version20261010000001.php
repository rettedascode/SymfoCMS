<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\PortableMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Multilingual content, step 2: once the previous migration has given every
 * page and post a translation group, guarantee one version per language in each group.
 */
final class Version20261010000001 extends PortableMigration
{
    public function getDescription(): string
    {
        return 'Allow only one language version per translation group';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('pages')->addUniqueIndex(['translation_group', 'locale'], 'uniq_pages_translation');
        $schema->getTable('posts')->addUniqueIndex(['translation_group', 'locale'], 'uniq_posts_translation');
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('pages')->dropIndex('uniq_pages_translation');
        $schema->getTable('posts')->dropIndex('uniq_posts_translation');
    }
}
