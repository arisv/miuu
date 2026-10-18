<?php

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * MariaDB's default collations ignore case, so columns that must tell "Cat" from "cat" get a binary
 * collation. This lives here rather than in the mapping because SQLite (tests) rejects the name.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchemaTable)]
class CaseSensitiveColumnsListener
{
    private const COLUMNS = ['file_aliases' => ['alias']];

    public function __construct(private Connection $connection)
    {
    }

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        $table = $args->getClassTable();
        $columns = self::COLUMNS[$table->getName()] ?? [];
        if (!$columns || !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }
        foreach ($columns as $column) {
            $table->getColumn($column)->setPlatformOption('collation', 'utf8mb4_bin');
        }
    }
}
