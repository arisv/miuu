<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'file_name_changes: rename history of stored files, for reverts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE file_name_changes (id INT AUTO_INCREMENT NOT NULL, old_name VARCHAR(255) NOT NULL, new_name VARCHAR(255) NOT NULL, is_revert TINYINT NOT NULL, created_at DATETIME NOT NULL, file_id INT NOT NULL, renamed_by_id INT DEFAULT NULL, INDEX IDX_8BD5460993CB796C (file_id), INDEX IDX_8BD5460982698BC4 (renamed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE file_name_changes ADD CONSTRAINT FK_8BD5460993CB796C FOREIGN KEY (file_id) REFERENCES filestorage (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE file_name_changes ADD CONSTRAINT FK_8BD5460982698BC4 FOREIGN KEY (renamed_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE file_name_changes');
    }
}
