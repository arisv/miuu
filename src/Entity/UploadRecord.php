<?php

namespace App\Entity;


use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'uploadlog')]
#[ORM\Entity]
class UploadRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    private $uploadId;
    #[ORM\ManyToOne(targetEntity: 'App\Entity\User')]
    #[ORM\JoinColumn(nullable: false)]
    private $user;
    #[ORM\ManyToOne(targetEntity: 'App\Entity\StoredFile')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private $image;

    /**
     * @return mixed
     */
    public function getUploadId()
    {
        return $this->uploadId;
    }

    public function setUploadId(mixed $uploadId): void
    {
        $this->uploadId = $uploadId;
    }

    /**
     * @return mixed
     */
    public function getUser()
    {
        return $this->user;
    }

    public function setUser(mixed $user): void
    {
        $this->user = $user;
    }

    /**
     * @return StoredFile | null
     */
    public function getImage()
    {
        return $this->image;
    }

    public function setImage(mixed $image): void
    {
        $this->image = $image;
    }

}