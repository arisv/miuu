<?php

namespace App\Entity;


use App\Service\UserService;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Index;

#[ORM\Table(name: 'filestorage')]
#[Index(name: 'search_idx', columns: ['custom_url'])]
#[ORM\Entity(repositoryClass: 'App\Repository\StoredFileRepository')]
class StoredFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    private $id;
    #[ORM\Column(type: 'string')]
    private $originalName;
    #[ORM\Column(type: 'string')]
    private $internalName;
    #[ORM\Column(type: 'string')]
    private $customUrl;
    #[ORM\Column(type: 'string')]
    private $serviceUrl;
    #[ORM\Column(type: 'string')]
    private $originalExtension;
    #[ORM\Column(type: 'string')]
    private $internalMimetype;
    #[ORM\Column(type: 'integer')]
    private $internalSize;
    #[ORM\Column(type: 'integer')]
    private $date;
    #[ORM\Column(type: 'boolean')]
    private $visibilityStatus;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private $markedForDeletionAt;

    private ?string $subdirectory = null;

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    public function setId(mixed $id)
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

    public function setOriginalName(mixed $originalName)
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

    public function setInternalName(mixed $internalName)
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

    public function setCustomUrl(mixed $customUrl)
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

    public function setServiceUrl(mixed $serviceUrl)
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

    public function setOriginalExtension(mixed $originalExtension)
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

    public function setInternalMimetype(mixed $internalMimetype)
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

    public function setInternalSize(mixed $internalSize)
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

    public function setDate(mixed $date)
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

    public function setVisibilityStatus(mixed $visibilityStatus)
    {
        $this->visibilityStatus = $visibilityStatus;
    }

    public function markedForDeletion()
    {
        if (is_null($this->markedForDeletionAt)) {
            return false;
        }
        return true;
    }

    public function isMimeType(array $types)
    {
        foreach ($types as $type) {
            if (str_starts_with((string) $this->internalMimetype, (string) $type))
                return true;
        }
        return false;
    }

    public function isThumbnailable(): bool
    {
        return $this->isMimeType(["image", "video"]);
    }

    public function shouldEmbed()
    {
        return $this->isMimeType(['image', 'audio', 'video/webm', 'video/mp4']);
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

    /**
     * @return mixed
     */
    public function getMarkedForDeletionAt()
    {
        return $this->markedForDeletionAt;
    }

    public function setMarkedForDeletionAt(mixed $markedForDeletionAt): void
    {
        $this->markedForDeletionAt = $markedForDeletionAt;
    }

    public function storageSubdirectory(): string
    {
        return $this->subdirectory ?? $this->subdirectory = (function () {
            $dt = new \DateTime();
            $dt->setTimestamp($this->getDate());
            return $dt->format('Y-m');
        })();
    }

}
