<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'file_aliases: custom URLs (/u/{alias}) of owned files, case sensitive';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE file_aliases (id INT AUTO_INCREMENT NOT NULL, alias VARCHAR(64) NOT NULL COLLATE `utf8mb4_bin`, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, upload_record_id INT NOT NULL, UNIQUE INDEX UNIQ_4740202E16C6B94 (alias), UNIQUE INDEX UNIQ_474020270629723 (upload_record_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE file_aliases ADD CONSTRAINT FK_474020270629723 FOREIGN KEY (upload_record_id) REFERENCES uploadlog (upload_id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE file_aliases');
    }
}
