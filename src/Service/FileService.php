<?php

namespace App\Service;


use App\Entity\StoredFile;
use App\Repository\StoredFileRepository;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class FileService
{
    private $em;
    private $logger;
    private $projectDir;

    public function __construct(EntityManagerInterface $em, LoggerInterface $logger, $projectDir)
    {
        $this->em = $em;
        $this->logger = $logger;
        $this->projectDir = $projectDir;
    }

    public function getFileByCustomURL($customUrl)
    {
        /** @var StoredFileRepository $fileRepo */
        $fileRepo = $this->em->getRepository(StoredFile::class);
        /** @var StoredFile $file */
        $file = $fileRepo->findFileByCustomURL($customUrl);
        if (!$file) {
            throw new \Exception("File ${customUrl} not found");
        }
        $path = $this->buildFullFilePath($file);
        return [$file, $path];
    }

    public function buildFullFilePath(StoredFile $file)
    {
        $storageDir = $_ENV['STORAGE_DIR'];
        if (!$storageDir) {
            throw new \Exception("Please configure STORAGE_DIR directory");
        }
        $dt = new \DateTime();
        $dt->setTimestamp($file->getDate());
        $path = $this->projectDir . '/' . $storageDir . '/' . $dt->format('Y-m') . '/' . $file->getInternalName();
        if (file_exists($path)) {
            return $path;
        } else {
            throw new \Exception("Path " . $path . " does not exist");
        }
    }

    public function readStream()
    {

    }

}