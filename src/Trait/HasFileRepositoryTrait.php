<?php

namespace App\Trait;

use App\Entity\StoredFile;
use App\Repository\StoredFileRepository;

trait HasFileRepositoryTrait
{
    protected ?StoredFileRepository $storedFileRepository = null;

    public function storedFileRepository(): StoredFileRepository
    {
        return $this->storedFileRepository ?? $this->storedFileRepository = $this->em->getRepository(StoredFile::class);
    }
}
