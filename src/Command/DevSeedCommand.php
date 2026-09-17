<?php

namespace App\Command;

use App\Entity\StoredFile;
use App\Entity\UploadRecord;
use App\Entity\User;
use App\Service\ThumbnailService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Dev-only seeder: known users plus a few hundred historical files with real bytes on disk,
 * spread over years and owners so the calendar, cursor pagination and admin pages have data.
 * Deterministic for a given --seed so URLs survive a --reset.
 */
#[AsCommand(
    name: 'app:dev:seed',
    description: 'Dev only: seed users and historical files with real bytes on disk',
)]
class DevSeedCommand extends Command
{
    // login, email, password, role, active, remote token
    private const USERS = [
        ['admin', 'admin@miu.local', 'admin', User::ROLE_ADMIN, true, 'dev-token-admin'],
        ['sysop', 'sysop@miu.local', 'sysop123', User::ROLE_ADMIN, true, 'dev-token-sysop'],
        ['alice', 'alice@miu.local', 'alice123', User::ROLE_USER, true, 'dev-token-alice'],
        ['bob', 'bob@miu.local', 'bob12345', User::ROLE_USER, true, 'dev-token-bob'],
        ['mallory', 'mallory@miu.local', 'mallory1', User::ROLE_USER, false, 'dev-token-mallory'],
    ];

    // Shares sum to 1.0; the empty key means anonymous (no UploadRecord).
    private const OWNER_SHARES = ['admin' => .30, 'sysop' => .10, 'alice' => .25, 'bob' => .15, 'mallory' => .05, '' => .15];
    private const KIND_SHARES = ['png' => .45, 'jpeg' => .40, 'webp' => .05, 'gif' => .04, 'txt' => .02, 'pdf' => .02, 'zip' => .02];

    /** One file of each kind the icon resolver distinguishes, appended to the newest month for the admin. */
    private const SHOWCASE = [
        'pdf' => 'manual.pdf', 'zip' => 'backup.zip', '7z' => 'photos.7z', 'gz' => 'logs.tar.gz', 'iso' => 'rescue-disk.iso',
        'docx' => 'letter.docx', 'xlsx' => 'budget.xlsx', 'csv' => 'export.csv', 'pptx' => 'pitch.pptx', 'epub' => 'novel.epub',
        'json' => 'config.json', 'html' => 'index.html', 'py' => 'script.py', 'sh' => 'deploy.sh', 'md' => 'README.md', 'txt' => 'notes.txt',
        'ttf' => 'Inter-Regular.ttf', 'woff2' => 'Inter.woff2', 'sqlite' => 'app.sqlite', 'deb' => 'miu_1.0_amd64.deb', 'exe' => 'setup.exe',
        'so' => 'libmiu.so', 'blend' => 'scene.blend', 'obj' => 'model.obj', 'wav' => 'voice-memo.wav', 'pem' => 'server.pem',
        'torrent' => 'linux.iso.torrent', 'xyz' => 'mystery.xyz',
    ];
    private const HIDDEN_COUNT = 8;
    private const FIRST_MONTH = '2017-01';
    private const RECENT_MONTHS = 12;
    private const RECENT_EXTRA = 200;
    private const CUSTOM_URL_ALPHABET = '123456789abcdefghijklmnopqrstuvwkyz';
    private const FLUSH_EVERY = 50;

    private Randomizer $rng;
    private Filesystem $fs;
    private MimeTypes $mimeTypes;
    /** @var array<string, true> */
    private array $usedCustomUrls = [];

    public function __construct(
        private EntityManagerInterface $em,
        private UserService $userService,
        private ThumbnailService $thumbnailService,
        private SymfonyStyle $io,
        private string $projectDir,
        private string $storageDir,
        #[Autowire('%kernel.environment%')] private string $environment,
    ) {
        parent::__construct();
        $this->fs = new Filesystem();
        $this->mimeTypes = MimeTypes::getDefault();
    }

    protected function configure(): void
    {
        $this
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Wipe users, files, upload log and storage/ first')
            ->addOption('files', null, InputOption::VALUE_REQUIRED, 'Number of files to create', '600')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'RNG seed, keeps URLs and names stable across runs', '20170101')
            ->addOption('skip-thumbnails', null, InputOption::VALUE_NONE, 'Do not generate thumbnails at the end');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->environment !== 'dev') {
            $this->io->error(sprintf('Refusing to seed in the "%s" environment. Dev only.', $this->environment));
            return Command::FAILURE;
        }

        // The injected SymfonyStyle wraps a fresh ArgvInput, so confirm() would ignore -n. Use the real input for prompts.
        $prompt = new SymfonyStyle($input, $output);
        $this->rng = new Randomizer(new Mt19937((int) $input->getOption('seed')));

        $alreadySeeded = (bool) $this->em->getRepository(User::class)->findOneBy(['login' => 'admin']);
        if ($input->getOption('reset')) {
            // Non-interactive runs (-n) count as consent; interactive ones must confirm.
            if ($input->isInteractive() && !$prompt->confirm('Wipe users, filestorage, uploadlog and everything under storage/?', false)) {
                return Command::SUCCESS;
            }
            $this->reset();
        } elseif ($alreadySeeded) {
            $this->io->warning('Database already seeded (user "admin" exists). Re-run with --reset to start over.');
            return Command::INVALID;
        }

        $users = $this->seedUsers();
        $plan = $this->buildPlan(max(1, (int) $input->getOption('files')));

        $this->io->section(sprintf('Storing %d files', count($plan)));
        $this->io->progressStart(count($plan));
        foreach (array_chunk($plan, self::FLUSH_EVERY) as $chunk) {
            foreach ($chunk as $spec) {
                $this->storeFile($spec, $users);
                $this->io->progressAdvance();
            }
            $this->em->flush();
            $this->em->clear();
            $users = $this->reloadUsers($users);
        }
        $this->io->progressFinish();

        if (!$input->getOption('skip-thumbnails')) {
            $this->io->section('Generating thumbnails');
            $this->thumbnailService->generateMissingThumbnails();
        }

        $this->printSummary($plan);
        return Command::SUCCESS;
    }

    private function reset(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['uploadlog', 'filestorage', 'users', 'messenger_messages'] as $table) {
            $conn->executeStatement(sprintf('TRUNCATE TABLE `%s`', $table));
        }
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $root = $this->storageRoot();
        $targets = glob($root . '/20[0-9][0-9]-[0-9][0-9]') ?: [];
        $targets[] = $root . '/' . ThumbnailService::THUMBNAIL_SUB_DIRECTORY;
        $this->fs->remove(array_filter($targets, 'is_dir'));
        $this->io->text('Database tables truncated and storage cleared.');
    }

    /** @return array<string, User> */
    private function seedUsers(): array
    {
        $users = [];
        foreach (self::USERS as [$login, $email, $password, $role, $active, $token]) {
            $user = $this->userService->createUser([
                'login' => $login,
                'email' => $email,
                'password' => $password,
                'role' => $role,
            ]);
            $user->setRemoteToken($token);
            $user->setActive($active);
            $users[$login] = $user;
        }
        $this->em->flush();
        return $users;
    }

    /** @param array<string, User> $users @return array<string, User> */
    private function reloadUsers(array $users): array
    {
        foreach ($users as $login => $user) {
            $users[$login] = $this->em->getReference(User::class, $user->getId());
        }
        return $users;
    }

    /** @return list<array<string, mixed>> */
    private function buildPlan(int $count): array
    {
        $months = $this->monthRange();
        $monthCount = count($months);

        // Two per month guaranteed, a heavier tail over the recent months, the rest scattered.
        $perMonth = array_fill(0, $monthCount, 0);
        $remaining = $count;
        for ($m = 0; $m < $monthCount && $remaining > 0; $m++) {
            $take = min(2, $remaining);
            $perMonth[$m] += $take;
            $remaining -= $take;
        }
        $recentStart = max(0, $monthCount - self::RECENT_MONTHS);
        for ($i = 0; $i < min(self::RECENT_EXTRA, $remaining); $i++) {
            $perMonth[$this->rng->getInt($recentStart, $monthCount - 1)]++;
        }
        $remaining -= min(self::RECENT_EXTRA, $remaining);
        for ($i = 0; $i < $remaining; $i++) {
            $perMonth[$this->rng->getInt(0, $monthCount - 1)]++;
        }

        $owners = $this->expandShares(self::OWNER_SHARES, $count);
        $kinds = $this->expandShares(self::KIND_SHARES, $count);
        if ((new ExecutableFinder())->find('ffmpeg') && ($pngIndex = array_search('png', $kinds, true)) !== false) {
            $kinds[$pngIndex] = 'mp4';
        }

        $now = time();
        $specs = [];
        $index = 0;
        foreach ($months as $m => $month) {
            [$start, $end] = $this->monthBounds($month, $now);
            for ($i = 0; $i < $perMonth[$m]; $i++) {
                $specs[] = [
                    'index' => $index,
                    'month' => $month,
                    'ts' => $this->rng->getInt($start, $end),
                    'owner' => $owners[$index] === '' ? null : $owners[$index],
                    'kind' => $kinds[$index],
                    'hidden' => $index < self::HIDDEN_COUNT,
                ];
                $index++;
            }
        }

        // Three files in the newest month share one timestamp so the cursor tie-breaker is exercised.
        $newest = array_filter($specs, fn ($s) => $s['month'] === end($months));
        $newestKeys = array_slice(array_keys($newest), 0, 3);
        if (count($newestKeys) === 3) {
            $shared = $specs[$newestKeys[0]]['ts'];
            foreach ($newestKeys as $k) {
                $specs[$k]['ts'] = $shared;
            }
        }

        [$start, $end] = $this->monthBounds(end($months), $now);
        foreach (self::SHOWCASE as $kind => $name) {
            $specs[] = [
                'index' => $index++,
                'month' => end($months),
                'ts' => $this->rng->getInt($start, $end),
                'owner' => 'admin',
                'kind' => $kind,
                'hidden' => false,
                'name' => $name,
            ];
        }

        foreach ($specs as &$spec) {
            $spec['name'] = $spec['name'] ?? $this->originalName($spec);
            $spec['customUrl'] = $this->customUrl();
            $spec['serviceUrl'] = bin2hex($this->rng->getBytes(16));
        }
        unset($spec);

        return $specs;
    }

    /** @return list<string> Y-m strings from FIRST_MONTH to the current month */
    private function monthRange(): array
    {
        $months = [];
        $cursor = new \DateTimeImmutable(self::FIRST_MONTH . '-01 00:00:00', new \DateTimeZone('UTC'));
        $last = new \DateTimeImmutable('first day of this month 00:00:00', new \DateTimeZone('UTC'));
        while ($cursor <= $last) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }
        return $months;
    }

    /** @return array{int, int} */
    private function monthBounds(string $month, int $now): array
    {
        $start = (new \DateTimeImmutable($month . '-01 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $end = (new \DateTimeImmutable($month . '-01 00:00:00', new \DateTimeZone('UTC')))->modify('+1 month')->getTimestamp() - 1;
        return [$start, min($end, $now - 60)];
    }

    /** @param array<string, float> $shares @return list<string> shuffled labels, exactly $count long */
    private function expandShares(array $shares, int $count): array
    {
        $labels = [];
        foreach ($shares as $label => $share) {
            $labels = array_merge($labels, array_fill(0, (int) floor($share * $count), (string) $label));
        }
        $fallback = (string) array_key_first($shares);
        while (count($labels) < $count) {
            $labels[] = $fallback;
        }
        return $this->rng->shuffleArray(array_slice($labels, 0, $count));
    }

    private function originalName(array $spec): string
    {
        $ext = $spec['kind'] === 'jpeg' ? 'jpg' : $spec['kind'];
        $dt = (new \DateTimeImmutable('@' . $spec['ts']))->setTimezone(new \DateTimeZone('UTC'));
        $templates = [
            fn () => sprintf('ShareX_%s.%s', $dt->format('Y-m-d_H-i-s'), $ext),
            fn () => sprintf('%s.%s', $dt->format('Y-m-d_H-i-s'), $ext),
            fn () => sprintf('chrome_%s.%s', $dt->format('Y-m-d_H-i-s'), $ext),
            fn () => sprintf('firefox_%s.%s', $this->randomString(10), $ext),
            fn () => sprintf('ffxiv_dx11_%s.%s', $this->randomString(10), $ext),
            fn () => sprintf('Screenshot %s.%s', $dt->format('Y-m-d His'), $ext),
            fn () => sprintf('IMG_%s.%s', $dt->format('Ymd_His'), $ext),
            fn () => sprintf('DSC_%04d.%s', $this->rng->getInt(1, 9999), $ext),
            fn () => sprintf('image.%s', $ext),
        ];
        if (in_array($spec['kind'], ['txt', 'pdf', 'zip', 'mp4'], true)) {
            $templates = [
                fn () => sprintf('notes_%s.%s', $dt->format('Y-m-d'), $ext),
                fn () => sprintf('archive_%s.%s', $this->randomString(6), $ext),
                fn () => sprintf('document.%s', $ext),
            ];
        }
        return $templates[$this->rng->getInt(0, count($templates) - 1)]();
    }

    private function randomString(int $length): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[$this->rng->getInt(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    /** Same shape as FileService::generateCustomURL(), but seeded and checked in memory. */
    private function customUrl(): string
    {
        $alphabetLength = strlen(self::CUSTOM_URL_ALPHABET);
        do {
            $token = '';
            for ($i = 0; $i < 10; $i++) {
                $token .= self::CUSTOM_URL_ALPHABET[$this->rng->getInt(0, $alphabetLength - 1)];
            }
        } while (isset($this->usedCustomUrls[$token]));
        $this->usedCustomUrls[$token] = true;
        return $token;
    }

    /** @param array<string, User> $users */
    private function storeFile(array $spec, array $users): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'seed');
        $this->writeBytes($spec, $tmp);

        $mime = $this->mimeTypes->guessMimeType($tmp) ?? 'application/octet-stream';
        $ext = $this->mimeTypes->getExtensions($mime)[0] ?? pathinfo($spec['name'], PATHINFO_EXTENSION) ?: 'bin';

        $file = new StoredFile();
        $file->setOriginalName($spec['name']);
        $file->setInternalSize(filesize($tmp));
        $file->setInternalMimetype($mime);
        $file->setOriginalExtension($ext);
        $file->setDate($spec['ts']);
        $file->setInternalName(sha1_file($tmp) . '_' . $spec['ts']);
        $file->setCustomUrl($spec['customUrl']);
        $file->setServiceUrl($spec['serviceUrl']);
        $file->setVisibilityStatus(!$spec['hidden']);
        $file->setMarkedForDeletionAt($spec['hidden'] ? new \DateTime() : null);

        $dir = $this->storageRoot() . '/' . gmdate('Y-m', $spec['ts']);
        $this->fs->mkdir($dir, 0775);
        $target = $dir . '/' . $file->getInternalName();
        $this->fs->rename($tmp, $target, true);
        // tempnam() creates 0600 files; the web server worker runs as another user and must be able to read them.
        $this->fs->chmod($target, 0644);

        $this->em->persist($file);
        if ($spec['owner'] !== null) {
            $record = new UploadRecord();
            $record->setImage($file);
            $record->setUser($users[$spec['owner']]);
            $this->em->persist($record);
        }
    }

    private function writeBytes(array $spec, string $path): void
    {
        switch ($spec['kind']) {
            case 'png':
            case 'jpeg':
            case 'webp':
            case 'gif':
                $this->writeImage($spec, $path);
                return;
            case 'txt':
                file_put_contents($path, str_repeat(sprintf("Seed file #%d from %s owned by %s.\n", $spec['index'], $spec['month'], $spec['owner'] ?? 'anonymous'), $this->rng->getInt(5, 400)));
                return;
            case 'pdf':
                file_put_contents($path, $this->minimalPdf(sprintf('Seed file #%d (%s)', $spec['index'], $spec['month'])));
                return;
            case 'zip':
                $zip = new \ZipArchive();
                $zip->open($path, \ZipArchive::OVERWRITE);
                $zip->addFromString('readme.txt', sprintf("Seed archive #%d\n", $spec['index']) . str_repeat("payload\n", $this->rng->getInt(10, 2000)));
                // ZipArchive stamps entries with the current time; pin it so reseeds produce identical bytes.
                $zip->setMtimeName('readme.txt', $spec['ts']);
                $zip->close();
                return;
            case 'mp4':
                $color = sprintf('0x%06X', $this->rng->getInt(0, 0xFFFFFF));
                (new Process(['ffmpeg', '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', "color=c={$color}:s=128x128:d=1", '-pix_fmt', 'yuv420p', '-f', 'mp4', $path]))->mustRun();
                return;
        }
        if (array_key_exists($spec['kind'], self::SHOWCASE)) {
            file_put_contents($path, $this->showcaseBytes($spec['kind'], $spec['ts']));
            return;
        }
        throw new \RuntimeException('Unknown seed kind ' . $spec['kind']);
    }

    /**
     * Small but genuine-looking bytes for each showcase kind: enough magic for mime sniffing to
     * classify them the way real uploads would be classified.
     */
    private function showcaseBytes(string $kind, int $ts): string
    {
        $officeZip = function (string $part, string $contentType) use ($ts): string {
            $tmp = tempnam(sys_get_temp_dir(), 'seedzip');
            $zip = new \ZipArchive();
            $zip->open($tmp, \ZipArchive::OVERWRITE);
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/' . $part . '" ContentType="' . $contentType . '"/></Types>');
            $zip->addFromString($part, '<?xml version="1.0" encoding="UTF-8"?><root/>');
            foreach (['[Content_Types].xml', $part] as $name) {
                $zip->setMtimeName($name, $ts);
            }
            $zip->close();
            $bytes = file_get_contents($tmp);
            unlink($tmp);
            return $bytes;
        };
        $zipWith = function (array $entries, bool $storedFirst = false) use ($ts): string {
            $tmp = tempnam(sys_get_temp_dir(), 'seedzip');
            $zip = new \ZipArchive();
            $zip->open($tmp, \ZipArchive::OVERWRITE);
            $first = true;
            foreach ($entries as $name => $content) {
                $zip->addFromString($name, $content);
                if ($first && $storedFirst) {
                    $zip->setCompressionName($name, \ZipArchive::CM_STORE);
                }
                $zip->setMtimeName($name, $ts);
                $first = false;
            }
            $zip->close();
            $bytes = file_get_contents($tmp);
            unlink($tmp);
            return $bytes;
        };
        switch ($kind) {
            case 'pdf': return $this->minimalPdf('Showcase PDF');
            case 'zip': return $zipWith(['readme.txt' => "Showcase archive\n"]);
            case '7z': return "7z\xBC\xAF\x27\x1C\x00\x04" . str_repeat("\x00", 64);
            case 'gz': return gzencode(str_repeat("log line\n", 200), 9);
            case 'iso': return str_repeat("\x00", 0x8000) . "\x01CD001\x01\x00" . str_pad('MIU RESCUE', 2040, "\x20");
            case 'docx': return $officeZip('word/document.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml');
            case 'xlsx': return $officeZip('xl/workbook.xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml');
            case 'pptx': return $officeZip('ppt/presentation.xml', 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml');
            case 'csv': return "id,name,size\n" . implode('', array_map(fn ($i) => "{$i},file{$i}," . ($i * 1024) . "\n", range(1, 50)));
            case 'epub': return $zipWith(['mimetype' => 'application/epub+zip', 'META-INF/container.xml' => '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>'], true);
            case 'json': return json_encode(['name' => 'miu', 'seed' => true, 'items' => range(1, 20)], JSON_PRETTY_PRINT);
            case 'html': return "<!DOCTYPE html>\n<html><head><title>Showcase</title></head><body><h1>Hello</h1></body></html>\n";
            case 'py': return "#!/usr/bin/env python3\nimport sys\n\nprint('showcase', sys.argv)\n";
            case 'sh': return "#!/bin/sh\nset -e\necho showcase\n";
            case 'md': return "# Showcase\n\nA *markdown* document with a list.\n\n- one\n- two\n";
            case 'txt': return str_repeat("Plain text showcase line.\n", 40);
            case 'ttf': return "\x00\x01\x00\x00\x00\x01\x00\x10\x00\x00\x00\x00" . 'cmap' . str_repeat("\x00", 128);
            case 'woff2': return 'wOF2' . "\x00\x01\x00\x00" . str_repeat("\x00", 40);
            case 'sqlite': return str_pad("SQLite format 3\x00\x10\x00\x01\x01\x00\x40\x20\x20", 4096, "\x00");
            case 'deb':
                $ar = function (string $name, string $body) use ($ts): string {
                    return str_pad($name, 16) . str_pad((string) $ts, 12) . str_pad('0', 6) . str_pad('0', 6) . str_pad('100644', 8) . str_pad((string) strlen($body), 10) . "`\n" . $body . (strlen($body) % 2 ? "\n" : '');
                };
                return "!<arch>\n" . $ar('debian-binary', "2.0\n") . $ar('control.tar.gz', gzencode("package: miu\n")) . $ar('data.tar.gz', gzencode("payload\n"));
            case 'exe': return 'MZ' . str_repeat("\x90", 62) . str_repeat("\x00", 448);
            case 'so': return "\x7fELF\x02\x01\x01\x00" . str_repeat("\x00", 8) . "\x03\x00\x3e\x00\x01\x00\x00\x00" . str_repeat("\x00", 40) . str_repeat("\x00", 512);
            case 'blend': return 'BLENDER-v300RENDH' . str_repeat("\x00", 256);
            case 'obj': return "# Showcase mesh\nv 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n";
            case 'wav':
                $samples = '';
                for ($i = 0; $i < 8000; $i++) {
                    $samples .= pack('v', (int) (sin($i / 8000 * 2 * M_PI * 440) * 12000) & 0xFFFF);
                }
                return 'RIFF' . pack('V', 36 + strlen($samples)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', strlen($samples)) . $samples;
            case 'pem': return "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode(str_repeat("\x30\x82", 120)), 64, "\n") . "-----END CERTIFICATE-----\n";
            case 'torrent': return 'd8:announce33:http://tracker.example/announce4:infod6:lengthi1024e4:name8:linux.iso12:piece lengthi16384e6:pieces20:' . str_repeat('a', 20) . 'ee';
            case 'xyz': return "\xDE\xAD\xBE\xEF" . str_repeat("\x7f\x00\xa5", 200);
        }
        throw new \RuntimeException('Unknown showcase kind ' . $kind);
    }

    private function writeImage(array $spec, string $path): void
    {
        $sizes = [[320, 240], [640, 480], [800, 600], [1280, 720], [1920, 1080]];
        [$w, $h] = $sizes[$this->rng->getInt(0, count($sizes) - 1)];
        $img = imagecreatetruecolor($w, $h);

        // Golden-angle hue per index keeps neighbouring thumbnails visibly distinct.
        [$r, $g, $b] = $this->hsvToRgb(fmod($spec['index'] * 137.508, 360.0), 0.55, 0.45);
        imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));

        $shapes = $this->rng->getInt(3, 6);
        for ($i = 0; $i < $shapes; $i++) {
            [$sr, $sg, $sb] = $this->hsvToRgb($this->rng->getInt(0, 359), 0.6, 0.85);
            $color = imagecolorallocatealpha($img, $sr, $sg, $sb, 40);
            $x1 = $this->rng->getInt(0, $w); $y1 = $this->rng->getInt(0, $h);
            $x2 = $this->rng->getInt(0, $w); $y2 = $this->rng->getInt(0, $h);
            if ($this->rng->getInt(0, 1)) {
                imagefilledrectangle($img, min($x1, $x2), min($y1, $y2), max($x1, $x2), max($y1, $y2), $color);
            } else {
                imagefilledellipse($img, $x1, $y1, abs($x2 - $x1) + 20, abs($y2 - $y1) + 20, $color);
            }
        }

        // Roughly a fifth get a noise strip so file sizes spread out for the size ordering.
        if ($this->rng->getInt(1, 5) === 1) {
            for ($y = 0; $y < min(64, $h); $y++) {
                for ($x = 0; $x < $w; $x++) {
                    imagesetpixel($img, $x, $y, imagecolorallocate($img, $this->rng->getInt(0, 255), $this->rng->getInt(0, 255), $this->rng->getInt(0, 255)));
                }
            }
        }

        $label = sprintf('#%d  %s  %s', $spec['index'], gmdate('Y-m-d', $spec['ts']), $spec['owner'] ?? 'anonymous');
        $white = imagecolorallocate($img, 255, 255, 255);
        $shadow = imagecolorallocate($img, 0, 0, 0);
        imagestring($img, 5, 13, (int) ($h / 2) + 1, $label, $shadow);
        imagestring($img, 5, 12, (int) ($h / 2), $label, $white);

        switch ($spec['kind']) {
            case 'png':
                imagepng($img, $path, $this->rng->getInt(0, 9));
                break;
            case 'jpeg':
                imagejpeg($img, $path, $this->rng->getInt(40, 95));
                break;
            case 'webp':
                imagewebp($img, $path, $this->rng->getInt(50, 90));
                break;
            case 'gif':
                imagegif($img, $path);
                break;
        }
        imagedestroy($img);
    }

    /** @return array{int, int, int} */
    private function hsvToRgb(float $h, float $s, float $v): array
    {
        $c = $v * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $v - $c;
        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0],
            $h < 120 => [$x, $c, 0],
            $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c],
            $h < 300 => [$x, 0, $c],
            default => [$c, 0, $x],
        };
        return [(int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255)];
    }

    private function minimalPdf(string $text): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = "BT /F1 18 Tf 72 720 Td ({$text}) Tj ET";
        $objects = [
            "<< /Type /Catalog /Pages 2 0 R >>",
            "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
            "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream",
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
        return $pdf;
    }

    private function storageRoot(): string
    {
        return rtrim($this->projectDir, '/') . '/' . trim($this->storageDir, '/');
    }

    /** @param list<array<string, mixed>> $plan */
    private function printSummary(array $plan): void
    {
        $this->io->section('Accounts');
        $this->io->table(
            ['Login', 'Password', 'Role', 'Active', 'Remote token'],
            array_map(fn ($u) => [$u[0], $u[2], $u[3] === User::ROLE_ADMIN ? 'admin' : 'user', $u[4] ? 'yes' : 'no', $u[5]], self::USERS)
        );

        $pick = function (callable $filter) use ($plan) {
            foreach ($plan as $spec) {
                if ($filter($spec)) {
                    return $spec;
                }
            }
            return null;
        };
        $rows = [];
        foreach ([
            'image' => $pick(fn ($s) => $s['kind'] === 'png' && !$s['hidden']),
            'zip' => $pick(fn ($s) => $s['kind'] === 'zip' && !$s['hidden']),
            'hidden (expect 404)' => $pick(fn ($s) => $s['hidden']),
        ] as $label => $spec) {
            if ($spec) {
                $ext = $spec['kind'] === 'jpeg' ? 'jpeg' : $spec['kind'];
                $rows[] = [$label, sprintf('http://localhost:8080/%s.%s', $spec['customUrl'], $ext)];
                if ($label === 'image') {
                    $rows[] = ['thumbnail', sprintf('http://localhost:8080/thumb/%s.%s', $spec['customUrl'], $ext)];
                }
            }
        }
        $this->io->section('Sample URLs');
        $this->io->table(['What', 'URL'], $rows);

        $owners = array_count_values(array_map(fn ($s) => $s['owner'] ?? 'anonymous', $plan));
        $this->io->text(sprintf('Files per owner: %s', implode(', ', array_map(fn ($k, $v) => "$k=$v", array_keys($owners), $owners))));
    }
}
