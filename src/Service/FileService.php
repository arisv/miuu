<?php

namespace App\Service;


use App\Entity\StoredFile;
use App\Entity\UploadRecord;
use App\Entity\User;
use App\Repository\StoredFileRepository;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Router;
use Symfony\Component\Routing\RouterInterface;

class FileService
{
    private $em;
    private $logger;
    private $projectDir;
    private $router;

    public function __construct(EntityManagerInterface $em, LoggerInterface $logger, RouterInterface $router, $projectDir)
    {
        $this->em = $em;
        $this->logger = $logger;
        $this->projectDir = $projectDir;
        $this->router = $router;
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

    public function storeFormUploadFile(UploadedFile $file, ?User $user)
    {
        if (!$file || !$file->isValid()) {
            throw new \Exception("File failed validation");
        }
        return $this->addFileToStorage($file, $user);
    }

    public function storeRemoteUploadFile(UploadedFile $file, $remoteToken)
    {
        if (!$file || !$file->isValid()) {
            throw new \Exception("File failed validation");
        }
        $user = $this->em->getRepository(User::class)->findActiveUserByToken($remoteToken);
        if (!$user) {
            throw new \Exception("Unable to find user by token " . $remoteToken);
        }
        return $this->addFileToStorage($file, $user);
    }

    public function generateFullURL(StoredFile $file)
    {
        return $this->router->generate('get_file_custom_url', [
            'customUrl' => $file->getCustomUrl(),
            'fileExtension'=> $file->getOriginalExtension()
        ], Router::ABSOLUTE_URL);
    }

    private function addFileToStorage(UploadedFile $file, ?User $user)
    {
        $storedFile = new StoredFile();
        $storedFile->setOriginalName($file->getClientOriginalName());
        $sha = sha1_file($file->getPathname());
        $storedFile->setInternalSize($file->getSize());
        $storedFile->setInternalMimetype($file->getMimeType());
        $extension = $file->guessExtension();
        if (!$extension) {
            $extension = "bin";
        }
        $storedFile->setOriginalExtension($extension);
        $storedFile->setDate(time());
        $storedFile->setInternalName($sha . '_' . $storedFile->getDate());
        $storedFile->setCustomUrl($this->generateCustomURL());
        $storedFile->setServiceUrl($this->generateServiceURL());
        $storedFile->setVisibilityStatus(true);
        $this->moveUploadedFile($file, $storedFile->getInternalName(), $storedFile->getDate());
        if ($user) {
            $log = new UploadRecord();
            $log->setImage($storedFile);
            $log->setUser($user);
            $this->em->persist($log);
        }
        $this->em->persist($storedFile);
        $this->em->flush();
        return $storedFile;
    }

    public function moveUploadedFile(UploadedFile $file, $newName, $overrideTimestamp = null)
    {
        $dt = new \DateTime();
        if ($overrideTimestamp) {
            $dt->setTimestamp($overrideTimestamp);
        } else {
            $dt->setTimestamp(time());
        }

        $storageDir = $_ENV['STORAGE_DIR'];
        if (!$storageDir) {
            throw new \Exception("Please configure STORAGE_DIR directory");
        }

        $storagePath = $this->projectDir . '/' . $storageDir . '/' . $dt->format('Y-m') . '/';
        $file->move($storagePath, $newName);
    }

    private function generateCustomURL()
    {
        $randomOffset = function ($min, $max) {
            $range = $max - $min;
            if ($range < 1) return $min;
            $log = ceil(log($range, 2));
            $bytes = (int)($log / 8) + 1;
            $bits = (int)$log + 1;
            $filter = (int)(1 << $bits) - 1;
            do {
                $rnd = hexdec(bin2hex(openssl_random_pseudo_bytes($bytes)));
                $rnd = $rnd & $filter;
            } while ($rnd >= $range);
            return $min + $rnd;
        };

        $alphabet = "123456789abcdefghijklmnopqrstuvwkyz";
        $alphabetLength = strlen($alphabet);
        $length = 10;
        do {
            $token = "";
            for ($i = 0; $i < $length; $i++) {
                $token .= $alphabet[$randomOffset(0, $alphabetLength)];
            }
            $exists = $this->em->getRepository(StoredFile::class)->findOneBy([
                'customUrl' => $token
            ]);
        } while ($exists);
        return $token;
    }

    private function generateServiceURL()
    {
        do {
            $token = bin2hex(openssl_random_pseudo_bytes(16));
            $exists = $this->em->getRepository(StoredFile::class)->findOneBy([
                'serviceUrl' => $token
            ]);
        } while ($exists);
        return $token;
    }


}