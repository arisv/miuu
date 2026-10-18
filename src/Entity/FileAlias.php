<?php

namespace App\Entity;

use App\Repository\FileAliasRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Custom URL of a file, served at /u/{alias}. At most one per upload record, so only files with an
 * owner have one; the owner is the record's user. Changing the alias frees the old one at once.
 * The column is case sensitive on MariaDB through CaseSensitiveColumnsListener.
 */
#[ORM\Table(name: 'file_aliases')]
#[ORM\Entity(repositoryClass: FileAliasRepository::class)]
class FileAlias
{
    public const PATTERN = '/^[A-Za-z0-9]{1,64}$/';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $alias;

    #[ORM\OneToOne(targetEntity: UploadRecord::class)]
    #[ORM\JoinColumn(name: 'upload_record_id', referencedColumnName: 'upload_id', nullable: false, onDelete: 'CASCADE')]
    private UploadRecord $uploadRecord;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(UploadRecord $uploadRecord, string $alias)
    {
        $this->uploadRecord = $uploadRecord;
        $this->alias = $alias;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAlias(): string
    {
        return $this->alias;
    }

    public function changeTo(string $alias): void
    {
        $this->alias = $alias;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUploadRecord(): UploadRecord
    {
        return $this->uploadRecord;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
