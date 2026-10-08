<?php

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * SQLite ignores foreign keys unless they are switched on per connection.
 * Without this, "ON DELETE SET NULL / CASCADE" would only work on MySQL and PostgreSQL.
 */
#[AutoconfigureTag('doctrine.middleware')]
final class SqliteForeignKeysMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);

                if (str_contains((string) ($params['driver'] ?? ''), 'sqlite')) {
                    $connection->exec('PRAGMA foreign_keys = ON');
                }

                return $connection;
            }
        };
    }
}
