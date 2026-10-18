<?php

namespace App\Entity;

use App\Repository\FileNameChangeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One rename of a stored file, kept so the owner can go back to an earlier name. Reverts are
 * recorded as renames too. Entries go with the file; the renamer is forgotten when deleted.
 */
#[ORM\Table(name: 'file_name_changes')]
#[ORM\Entity(repositoryClass: FileNameChangeRepository::class)]
class FileNameChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\ManyToOne(targetEntity: StoredFile::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private StoredFile $file;

    #[ORM\Column(type: 'string', length: 255)]
    private string $oldName;

    #[ORM\Column(type: 'string', length: 255)]
    private string $newName;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $renamedBy;

    #[ORM\Column(name: 'is_revert', type: 'boolean')]
    private bool $revert;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(StoredFile $file, string $oldName, string $newName, ?User $renamedBy, bool $revert = false)
    {
        $this->file = $file;
        $this->oldName = $oldName;
        $this->newName = $newName;
        $this->renamedBy = $renamedBy;
        $this->revert = $revert;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFile(): StoredFile
    {
        return $this->file;
    }

    public function getOldName(): string
    {
        return $this->oldName;
    }

    public function getNewName(): string
    {
        return $this->newName;
    }

    public function getRenamedBy(): ?User
    {
        return $this->renamedBy;
    }

    public function isRevert(): bool
    {
        return $this->revert;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
