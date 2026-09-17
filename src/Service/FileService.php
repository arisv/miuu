<?php

namespace App\Service;


use App\Entity\StoredFile;
use App\Entity\UploadRecord;
use App\Entity\User;
use App\Exception\FileNotInStorageException;
use App\Repository\StoredFileRepository;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Tests\Compiler\D;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Router;
use Symfony\Component\Routing\RouterInterface;

class FileService
{
    const DELETED_OK = 1;
    const DELETED_NOT_ON_DISK = 2;
    const DELETED_FAIL = 3;

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private RouterInterface $router,
        private ThumbnailService $thumbnailService,
        private string $projectDir,
        private string $storageDir,
        private string $appSecret
    ) {
    }

    /**
     * Deletion links are stateless: the key is an HMAC over the file's identity with the app secret,
     * so an upload client can act on its own files without a session or its token.
     */
    public function generateDeletionKey(StoredFile $file): string
    {
        return substr(hash_hmac('sha256', $file->getId() . ':' . $file->getInternalName(), $this->appSecret), 0, 32);
    }

    public function verifyDeletionKey(StoredFile $file, string $key): bool
    {
        return $key !== '' && hash_equals($this->generateDeletionKey($file), $key);
    }

    public function generateDeletionURL(StoredFile $file): string
    {
        return $this->router->generate('delete_file', [
            'customUrl' => $file->getCustomUrl(),
            'fileExtension' => $file->getOriginalExtension(),
            'key' => $this->generateDeletionKey($file)
        ], Router::ABSOLUTE_URL);
    }

    public function markForDeletion(StoredFile $file): void
    {
        $file->setVisibilityStatus(false);
        $file->setMarkedForDeletionAt(new \DateTime());
        $this->em->flush();
    }

    public function getFileByCustomURL($customUrl)
    {
        /** @var StoredFileRepository $fileRepo */
        $fileRepo = $this->em->getRepository(StoredFile::class);
        /** @var StoredFile $file */
        $file = $fileRepo->findFileByCustomURL($customUrl);
        if (!$file) {
            throw new \Exception("File {$customUrl} not found");
        }
        $path = $this->buildFullFilePath($file);
        return [$file, $path];
    }

    public function buildFullFilePath(StoredFile $file)
    {
        $dt = new \DateTime();
        $dt->setTimestamp($file->getDate());
        $path = join(DIRECTORY_SEPARATOR, [
            $this->projectDir,
            $this->storageDir,
            $dt->format('Y-m'),
            $file->getInternalName()
        ]);
        if (file_exists($path)) {
            return $path;
        } else {
            throw new FileNotInStorageException("Path " . $path . " does not exist");
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
            'fileExtension' => $file->getOriginalExtension()
        ], Router::ABSOLUTE_URL);
    }

    public function generateViewURL(StoredFile $file)
    {
        return $this->router->generate('view_file', [
            'customUrl' => $file->getCustomUrl(),
            'fileExtension' => $file->getOriginalExtension()
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
        $this->thumbnailService->scheduleThumbnailGeneration($storedFile);
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

    public function mirrorRemoteFile($url, ?User $user)
    {
        $this->validateUrl($url);
        $remoteSize = $this->queryRemoteFileSize($url);
        if ($remoteSize < 1) {
            throw new \Exception("Unable to get filesize from remote target");
        }
        $maxFileSize = $_ENV['MAX_REMOTE_FILE_SIZE'];
        if ($remoteSize > $maxFileSize) {
            throw new \Exception("Remote filesize is too big");
        }

        $savedFile = $this->fetchRemoteFile($url, $remoteSize);
        $extractedFilename = $this->extractFilenameFromUrl($url);

        $uploadedFile = new UploadedFile(
            path: $savedFile,
            originalName: $extractedFilename,
            test: true
        );

        if (empty($uploadedFile) || !$uploadedFile->isValid()) {
            throw new \Exception("Could not construct valid UploadedFile from remote file");
        }

        return $this->addFileToStorage($uploadedFile, $user);
    }

    private function IPinRange($ip, $range)
    {
        list($range, $netmask) = explode('/', $range, 2);
        $range_decimal = ip2long($range);
        $ip_decimal = ip2long($ip);
        $wildcard_decimal = pow(2, (32 - $netmask)) - 1;
        $netmask_decimal = ~$wildcard_decimal;
        return (($ip_decimal & $netmask_decimal) == ($range_decimal & $netmask_decimal));
    }

    private function validateUrl($url)
    {
        $structure = parse_url($url);
        $scheme = $structure['scheme'] ?? 'https';
        if (!in_array($scheme, ['http', 'https'])) {
            throw new \Exception("Unsupported protocol");
        }
        $host = $structure['host'] ?? null;
        if (!$host) {
            throw new \Exception("Host unspecified");
        }
        $ip = gethostbyname($host);
        if (!$ip) {
            throw new \Exception("Host unresolvable");
        }
        $rangeBlacklist = [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16'
        ];
        foreach ($rangeBlacklist as $range) {
            $is = $this->IPinRange($ip, $range);
            if ($is) {
                throw new \Exception("Network unreachable");
            }
        }
    }

    private function queryRemoteFileSize($url)
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, TRUE);
        curl_setopt($ch, CURLOPT_NOBODY, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $data = curl_exec($ch);
        $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);

        curl_close($ch);
        return $size;
    }

    public function fetchRemoteFile($url, $fileSize)
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'miufile');
        $fp = fopen($tempPath, 'w');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        $data = curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        return $tempPath;
    }

    public function extractFilenameFromUrl($url)
    {
        $pathinfo = pathinfo($url);
        if (!isset($pathinfo['filename']) || $pathinfo['filename'] == "") {
            return time();
        }
        return $pathinfo['filename'];
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

    public function setDeleteStatus(User $user, $fileId, $action)
    {
        $file = $this->findFileManageableBy($user, $fileId);
        if ($action == 'del') {
            $file->setVisibilityStatus(false);
            $file->setMarkedForDeletionAt(new \DateTime());
        } else {
            $file->setVisibilityStatus(true);
            $file->setMarkedForDeletionAt(null);
        }
        $this->em->flush();
    }

    /** Owner of the upload record, or any admin. Anonymous visitors manage nothing. */
    public function canManage(?User $user, StoredFile $file): bool
    {
        if (!$user) {
            return false;
        }
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }
        return (bool) $this->em->getRepository(UploadRecord::class)->findOneBy([
            'user' => $user,
            'image' => $file
        ]);
    }

    /** For the HTML view page: hidden files resolve too, so their managers can still undelete them. */
    public function getFileForView(string $customUrl): ?StoredFile
    {
        return $this->em->getRepository(StoredFile::class)->findFileByCustomURLAnyVisibility($customUrl);
    }

    /**
     * A user may manage files they uploaded. An admin may manage any file,
     * including anonymous ones with no upload record at all.
     */
    private function findFileManageableBy(User $user, $fileId): StoredFile
    {
        $recordRepo = $this->em->getRepository(UploadRecord::class);
        $record = $recordRepo->findOneBy([
            'user' => $user,
            'image' => $fileId
        ]);
        if ($record) {
            return $record->getImage();
        }

        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            $file = $this->em->getRepository(StoredFile::class)->find($fileId);
            if ($file) {
                return $file;
            }
        }

        throw new \Exception("File not found");
    }

    public function deleteMarkedFiles($token)
    {
        $result = [
            'deleted' => [],
            'orphaned' => []
        ];
        if ($_ENV['WORKER_TOKEN'] != $token) {
            throw new \Exception("Unauthorized invocation");
        }

        $pivot = $this->getDeletionPivotDate();

        $filesToDelete = $this->em->createQueryBuilder()
            ->select('file')
            ->from('App\Entity\StoredFile', 'file')
            ->where('file.markedForDeletionAt IS NOT NULL AND file.markedForDeletionAt < :pivot')
            ->setParameter('pivot', $pivot)
            ->getQuery()
            ->execute();

        foreach ($filesToDelete as $file) {
            $report = $this->deleteFileFromStorage($file);
            if ($report['status'] == self::DELETED_OK) {
                $result['deleted'][] = $report['id'];
            }
            if ($report['status'] == self::DELETED_NOT_ON_DISK) {
                $result['orphaned'][] = $report['id'];
            }
        }

        return $result;
    }

    public function deleteFileFromStorage(StoredFile $file)
    {
        $result = [
            'status' => self::DELETED_OK,
            'id' => $file->getId()
        ];
        try {
            $path = $this->buildFullFilePath($file);
            $fs = new Filesystem();
            $fs->remove($path);

            $thumbPath = join(DIRECTORY_SEPARATOR, [
                $this->projectDir,
                $this->storageDir,
                $file->relativeThumbPath()
            ]);
            $fs->remove($thumbPath);
        } catch (FileNotInStorageException $fse) {
            $result['status'] = self::DELETED_NOT_ON_DISK;
        }
        $this->em->remove($file);
        $this->em->flush();
        return $result;
    }

    public function getAllFilesBySize()
    {
        $limit = 50;
        $fileSql = <<<SQL
SELECT id, custom_url, original_extension, original_name, internal_size, internal_mimetype, marked_for_deletion_at, user_id
FROM filestorage
LEFT JOIN uploadlog u on filestorage.id = u.image_id
ORDER BY internal_size DESC LIMIT $limit
SQL;
        $stmt = $this->em->getConnection()->prepare($fileSql);
        $files = $stmt->executeQuery()->fetchAllAssociative();
        $userIdsEncountered = [];
        $result = [];
        foreach ($files as $fileData) {
            $userIdsEncountered[] = $fileData['user_id'];
            $temp = [
                'file_id' => $fileData['id'],
                'user_id' => $fileData['user_id'],
                'name' => $fileData['original_name'],
                'url' => $fileData['custom_url'],
                'size' => UserService::formatSize($fileData['internal_size'])
            ];
            $temp['extension'] = $fileData['original_extension'];
            if (!$temp['extension']) {
                $temp['extension'] = "bin";
            }
            $mime = (string) $fileData['internal_mimetype'];
            $temp['thumbnailable'] = str_starts_with($mime, 'image') || str_starts_with($mime, 'video');
            $temp['marked_for_deletion'] = $fileData['marked_for_deletion_at'] !== null;
            $result[] = $temp;
        }
        $users = $this->em->getRepository(User::class)->findUsersByList(array_unique($userIdsEncountered));
        return [$result, $users];
    }

    public function getDeletionPivotDate()
    {
        $now = new \DateTime();
        $minutesAgo = intval($_ENV['DELETE_MARKED_FILES_AFTER_MINUTES']) ?? 5;
        $interval = new \DateInterval("PT{$minutesAgo}M");
        $now->sub($interval);
        return $now;
    }
}
