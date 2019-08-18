<?php

namespace App\Entity;


use App\Service\UserService;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Index;

/**
 * @ORM\Entity(repositoryClass="App\Repository\StoredFileRepository")
 * @ORM\Table(name="filestorage", indexes={@Index(name="search_idx", columns="custom_url")})
 */
class StoredFile
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @ORM\Column(type="integer")
     */
    private $id;
    /**
     * @ORM\Column(type="string")
     */
    private $originalName;
    /**
     * @ORM\Column(type="string")
     */
    private $internalName;
    /**
     * @ORM\Column(type="string")
     */
    private $customUrl;
    /**
     * @ORM\Column(type="string")
     */
    private $serviceUrl;
    /**
     * @ORM\Column(type="string")
     */
    private $originalExtension;
    /**
     * @ORM\Column(type="string")
     */
    private $internalMimetype;
    /**
     * @ORM\Column(type="integer")
     */
    private $internalSize;
    /**
     * @ORM\Column(type="integer")
     */
    private $date;
    /**
     * @ORM\Column(type="boolean")
     */
    private $visibilityStatus;

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param mixed $id
     */
    public function setId($id)
    {
        $this->id = $id;
    }

    /**
     * @return mixed
     */
    public function getOriginalName()
    {
        return $this->originalName;
    }

    /**
     * @param mixed $originalName
     */
    public function setOriginalName($originalName)
    {
        $this->originalName = $originalName;
    }

    /**
     * @return mixed
     */
    public function getInternalName()
    {
        return $this->internalName;
    }

    /**
     * @param mixed $internalName
     */
    public function setInternalName($internalName)
    {
        $this->internalName = $internalName;
    }

    /**
     * @return mixed
     */
    public function getCustomUrl()
    {
        return $this->customUrl;
    }

    /**
     * @param mixed $customUrl
     */
    public function setCustomUrl($customUrl)
    {
        $this->customUrl = $customUrl;
    }

    /**
     * @return mixed
     */
    public function getServiceUrl()
    {
        return $this->serviceUrl;
    }

    /**
     * @param mixed $serviceUrl
     */
    public function setServiceUrl($serviceUrl)
    {
        $this->serviceUrl = $serviceUrl;
    }

    /**
     * @return mixed
     */
    public function getOriginalExtension()
    {
        return $this->originalExtension;
    }

    /**
     * @param mixed $originalExtension
     */
    public function setOriginalExtension($originalExtension)
    {
        $this->originalExtension = $originalExtension;
    }

    /**
     * @return mixed
     */
    public function getInternalMimetype()
    {
        return $this->internalMimetype;
    }

    /**
     * @param mixed $internalMimetype
     */
    public function setInternalMimetype($internalMimetype)
    {
        $this->internalMimetype = $internalMimetype;
    }

    /**
     * @return mixed
     */
    public function getInternalSize()
    {
        return $this->internalSize;
    }

    /**
     * @param mixed $internalSize
     */
    public function setInternalSize($internalSize)
    {
        $this->internalSize = $internalSize;
    }

    /**
     * @return mixed
     */
    public function getDate()
    {
        return $this->date;
    }

    /**
     * @param mixed $date
     */
    public function setDate($date)
    {
        $this->date = $date;
    }

    /**
     * @return mixed
     */
    public function getVisibilityStatus()
    {
        return $this->visibilityStatus;
    }

    /**
     * @param mixed $visibilityStatus
     */
    public function setVisibilityStatus($visibilityStatus)
    {
        $this->visibilityStatus = $visibilityStatus;
    }

    public function markedForDeletion()
    {
        return $this->visibilityStatus == false;
    }

    public function isMimeType(array $types)
    {
        foreach ($types as $type) {
            if (strpos($this->internalMimetype, $type) === 0)
                return true;
        }
        return false;
    }

    public function shouldEmbed()
    {
        return $this->isMimeType(['image', 'audio', 'video/webm']);
    }

    public function getFullFilePath($projectRoot)
    {
        $dt = new \DateTime();
        $dt->setTimestamp($this->date);
        $path = __DIR__.'/../storage/'.$dt->format('Y-m').'/'.$this->internalName;
        if(file_exists($path))
            return $path;
        else
            return "";
    }

    public function getFileSizeFormatted()
    {
        return UserService::formatSize($this->internalSize);
    }

}