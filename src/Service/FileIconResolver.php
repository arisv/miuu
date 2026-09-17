<?php

namespace App\Service;

/**
 * Picks a Font Awesome icon for a file from its stored mime type, falling back to the
 * extension for the generic application/octet-stream that many uploaders send.
 */
class FileIconResolver
{
    public const FALLBACK = 'fa-file';

    /** Ordered: first match wins. Patterns are regexes against the lower-cased mime type. */
    private const MIME_RULES = [
        ['#^application/pdf$#', 'fa-file-pdf'],
        ['#^application/(zip|x-7z-compressed|x-rar-compressed|vnd\.rar|gzip|x-gzip|x-tar|x-bzip2|x-xz|zstd|x-zstd|x-compressed)$#', 'fa-file-zipper'],
        ['#^application/x-iso9660-image$#', 'fa-compact-disc'],
        ['#^application/(msword|vnd\.openxmlformats-officedocument\.wordprocessingml.*|vnd\.oasis\.opendocument\.text|rtf)$#', 'fa-file-word'],
        ['#^application/(vnd\.ms-excel.*|vnd\.openxmlformats-officedocument\.spreadsheetml.*|vnd\.oasis\.opendocument\.spreadsheet)$#', 'fa-file-excel'],
        ['#^text/(csv|tab-separated-values)$#', 'fa-file-csv'],
        ['#^application/(vnd\.ms-powerpoint.*|vnd\.openxmlformats-officedocument\.presentationml.*|vnd\.oasis\.opendocument\.presentation)$#', 'fa-file-powerpoint'],
        ['#^application/(epub\+zip|x-mobipocket-ebook)$#', 'fa-book'],
        ['#^application/(x-sh|x-shellscript|x-bat|x-powershell)$#', 'fa-terminal'],
        ['#^(application/(json|ld\+json|xml|javascript|x-javascript|x-yaml|yaml|x-httpd-php|x-php|toml)|text/(xml|html|css|javascript|x-.*|yaml|markdown\+.*))$#', 'fa-file-code'],
        ['#^text/#', 'fa-file-lines'],
        ['#^(font/|application/(font-.*|x-font-.*|vnd\.ms-fontobject)$)#', 'fa-font'],
        ['#^application/(x-sqlite3|vnd\.sqlite3|sql|x-sql)$#', 'fa-database'],
        ['#^application/(vnd\.android\.package-archive|x-msi|x-ms-installer|x-debian-package|vnd\.debian\.binary-package|x-rpm|x-redhat-package-manager|x-apple-diskimage)$#', 'fa-box-archive'],
        ['#^application/(x-dosexec|x-msdownload|x-executable|x-sharedlib|x-mach-binary|x-pie-executable|vnd\.microsoft\.portable-executable|x-ms-dos-executable)$#', 'fa-microchip'],
        ['#^(model/|application/x-blender$)#', 'fa-cube'],
        ['#^(image/vnd\.adobe\.photoshop|application/(x-photoshop|photoshop|psd|illustrator|x-krita|x-xcf)|image/x-xcf)$#', 'fa-brush'],
        ['#^image/#', 'fa-file-image'],
        ['#^audio/#', 'fa-file-audio'],
        ['#^video/#', 'fa-file-video'],
        ['#^application/(pgp-.*|pkcs.*|x-pkcs.*|x-x509-.*|x-pem-file)$#', 'fa-file-shield'],
        ['#^application/x-bittorrent$#', 'fa-file-arrow-down'],
    ];

    private const EXTENSION_RULES = [
        'pdf' => 'fa-file-pdf',
        'zip' => 'fa-file-zipper', '7z' => 'fa-file-zipper', 'rar' => 'fa-file-zipper', 'gz' => 'fa-file-zipper', 'tgz' => 'fa-file-zipper', 'tar' => 'fa-file-zipper', 'bz2' => 'fa-file-zipper', 'xz' => 'fa-file-zipper', 'zst' => 'fa-file-zipper',
        'iso' => 'fa-compact-disc', 'img' => 'fa-compact-disc', 'dmg' => 'fa-compact-disc',
        'doc' => 'fa-file-word', 'docx' => 'fa-file-word', 'odt' => 'fa-file-word', 'rtf' => 'fa-file-word',
        'xls' => 'fa-file-excel', 'xlsx' => 'fa-file-excel', 'ods' => 'fa-file-excel',
        'csv' => 'fa-file-csv', 'tsv' => 'fa-file-csv',
        'ppt' => 'fa-file-powerpoint', 'pptx' => 'fa-file-powerpoint', 'odp' => 'fa-file-powerpoint',
        'epub' => 'fa-book', 'mobi' => 'fa-book',
        'sh' => 'fa-terminal', 'bash' => 'fa-terminal', 'zsh' => 'fa-terminal', 'bat' => 'fa-terminal', 'cmd' => 'fa-terminal', 'ps1' => 'fa-terminal',
        'json' => 'fa-file-code', 'xml' => 'fa-file-code', 'html' => 'fa-file-code', 'htm' => 'fa-file-code', 'css' => 'fa-file-code', 'js' => 'fa-file-code', 'ts' => 'fa-file-code', 'php' => 'fa-file-code', 'py' => 'fa-file-code', 'rb' => 'fa-file-code', 'go' => 'fa-file-code', 'rs' => 'fa-file-code', 'c' => 'fa-file-code', 'h' => 'fa-file-code', 'cpp' => 'fa-file-code', 'java' => 'fa-file-code', 'yml' => 'fa-file-code', 'yaml' => 'fa-file-code', 'toml' => 'fa-file-code', 'ini' => 'fa-file-code',
        'txt' => 'fa-file-lines', 'md' => 'fa-file-lines', 'log' => 'fa-file-lines', 'nfo' => 'fa-file-lines',
        'ttf' => 'fa-font', 'otf' => 'fa-font', 'woff' => 'fa-font', 'woff2' => 'fa-font', 'eot' => 'fa-font',
        'db' => 'fa-database', 'sqlite' => 'fa-database', 'sqlite3' => 'fa-database', 'sql' => 'fa-database',
        'apk' => 'fa-box-archive', 'msi' => 'fa-box-archive', 'deb' => 'fa-box-archive', 'rpm' => 'fa-box-archive', 'pkg' => 'fa-box-archive', 'appimage' => 'fa-box-archive', 'flatpak' => 'fa-box-archive',
        'exe' => 'fa-microchip', 'dll' => 'fa-microchip', 'so' => 'fa-microchip', 'dylib' => 'fa-microchip', 'elf' => 'fa-microchip',
        'blend' => 'fa-cube', 'obj' => 'fa-cube', 'fbx' => 'fa-cube', 'stl' => 'fa-cube', 'gltf' => 'fa-cube', 'glb' => 'fa-cube',
        'psd' => 'fa-brush', 'ai' => 'fa-brush', 'xcf' => 'fa-brush', 'kra' => 'fa-brush', 'clip' => 'fa-brush', 'procreate' => 'fa-brush',
        'pem' => 'fa-file-shield', 'crt' => 'fa-file-shield', 'cer' => 'fa-file-shield', 'key' => 'fa-file-shield', 'gpg' => 'fa-file-shield', 'asc' => 'fa-file-shield', 'p12' => 'fa-file-shield', 'pfx' => 'fa-file-shield',
        'torrent' => 'fa-file-arrow-down',
    ];

    /**
     * Mime types that content sniffing hands out for many unrelated formats; the extension is the
     * better witness for these, so it is consulted first.
     */
    private const AMBIGUOUS_MIMES = ['', 'application/octet-stream', 'binary/octet-stream', 'text/plain', 'application/zip', 'application/x-empty', 'inode/x-empty'];

    /** Returns the icon class (without the fa-solid prefix). */
    public function resolve(?string $mime, ?string $extension = null, ?string $originalName = null): string
    {
        $mime = strtolower(trim((string) $mime));
        // The stored extension is derived from the sniffed mime type, so the original file name
        // is the more specific hint and goes first.
        $candidates = [$originalName ? pathinfo($originalName, PATHINFO_EXTENSION) : null, $extension];
        $byExtension = null;
        foreach ($candidates as $ext) {
            $ext = strtolower(trim((string) $ext, ". \t"));
            if ($ext !== '' && isset(self::EXTENSION_RULES[$ext])) {
                $byExtension = self::EXTENSION_RULES[$ext];
                break;
            }
        }
        if ($byExtension !== null && in_array($mime, self::AMBIGUOUS_MIMES, true)) {
            return $byExtension;
        }
        foreach (self::MIME_RULES as [$pattern, $icon]) {
            if (preg_match($pattern, $mime)) {
                return $icon;
            }
        }
        return $byExtension ?? self::FALLBACK;
    }
}
