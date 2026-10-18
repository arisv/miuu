<?php

namespace App\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * The few MySQL date functions the app's raw SQL uses (gallery month tree, MONTH_KEY), defined on
 * the SQLite test connection so pages with a gallery can be rendered in tests. Times are UTC.
 */
final class SqliteMysqlFunctions implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            /** {@inheritDoc} */
            public function connect(#[SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);
                $pdo = $connection->getNativeConnection();
                if ($pdo instanceof \PDO && $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $pdo->sqliteCreateFunction('FROM_UNIXTIME', static fn ($ts) => gmdate('Y-m-d H:i:s', (int) $ts), 1);
                    $pdo->sqliteCreateFunction('YEAR', static fn ($dt) => (int) substr((string) $dt, 0, 4), 1);
                    $pdo->sqliteCreateFunction('MONTH', static fn ($dt) => (int) substr((string) $dt, 5, 2), 1);
                    $pdo->sqliteCreateFunction('DATE_FORMAT', static fn ($dt, $format) => (new \DateTimeImmutable((string) $dt, new \DateTimeZone('UTC')))
                        ->format(strtr((string) $format, ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%H' => 'H', '%i' => 'i', '%s' => 's'])), 2);
                }
                return $connection;
            }
        };
    }
}
