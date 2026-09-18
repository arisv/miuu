<?php

namespace App\Entity;

use App\Service\ThumbnailService;
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
    private ?string $relativePath = null;
    private ?string $relativeThumbPath = null;

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

    /** Plain text, markup and source files that can be shown inline as text. */
    public function isTextual(): bool
    {
        $mime = strtolower((string) $this->internalMimetype);
        if (str_starts_with($mime, 'text/')) {
            return true;
        }
        return in_array($mime, [
            'application/json', 'application/ld+json', 'application/xml', 'application/javascript', 'application/x-javascript',
            'application/x-yaml', 'application/yaml', 'application/x-sh', 'application/x-shellscript', 'application/x-httpd-php',
            'application/x-php', 'application/toml', 'application/sql', 'application/x-empty',
        ], true);
    }

    /** How clients should present the file: image | video | audio | text | file. */
    public function previewKind(): string
    {
        if ($this->isMimeType(['image'])) {
            return 'image';
        }
        if ($this->isMimeType(['video'])) {
            return 'video';
        }
        if ($this->isMimeType(['audio'])) {
            return 'audio';
        }
        return $this->isTextual() ? 'text' : 'file';
    }

    public function isThumbnailable(): bool
    {
        return $this->isMimeType(["image", "video"]);
    }

    public function shouldEmbed()
    {
        return $this->isMimeType(['image', 'audio', 'video']);
    }

    public function getFullFilePath($projectRoot)
    {
        $dt = new \DateTime();
        $dt->setTimestamp($this->date);
        $path = __DIR__ . '/../storage/' . $dt->format('Y-m') . '/' . $this->internalName;
        if (file_exists($path))
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

    public function relativePath(): string
    {
        return $this->relativePath ?? $this->relativePath = (function () {
            return join(DIRECTORY_SEPARATOR, [
                $this->storageSubdirectory(),
                $this->getInternalName()
            ]);
        })();
    }

    public function relativeThumbPath(): string
    {
        return $this->relativeThumbPath ?? $this->relativeThumbPath = (function () {
            return join(DIRECTORY_SEPARATOR, [
                ThumbnailService::THUMBNAIL_SUB_DIRECTORY,
                $this->storageSubdirectory(),
                $this->getInternalName()
            ]);
        })();
    }
}
