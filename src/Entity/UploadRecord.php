<?php

namespace App\Entity;


use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="uploadlog")
 */
class UploadRecord
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @ORM\Column(type="integer")
     */
    private $uploadId;
    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\User")
     * @ORM\JoinColumn(nullable=false)
     */
    private $user;
    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\StoredFile")
     * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
     */
    private $image;

    /**
     * @return mixed
     */
    public function getUploadId()
    {
        return $this->uploadId;
    }

    /**
     * @param mixed $uploadId
     */
    public function setUploadId($uploadId): void
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

    /**
     * @param mixed $user
     */
    public function setUser($user): void
    {
        $this->user = $user;
    }

    /**
     * @return mixed
     */
    public function getImage()
    {
        return $this->image;
    }

    /**
     * @param mixed $image
     */
    public function setImage($image): void
    {
        $this->image = $image;
    }

}