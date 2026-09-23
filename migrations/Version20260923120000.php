<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index filestorage.internal_name (sha1 dedupe lookups) and service_url (checked on every insert)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX internal_name_idx ON filestorage (internal_name)');
        $this->addSql('CREATE INDEX service_url_idx ON filestorage (service_url)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX internal_name_idx ON filestorage');
        $this->addSql('DROP INDEX service_url_idx ON filestorage');
    }
}
