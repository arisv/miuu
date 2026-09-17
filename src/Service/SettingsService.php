<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

/**
 * Admin-editable toggles. They live in .env.local (loaded over .env by Symfony's Dotenv), so the
 * repository's .env stays untouched and the change applies on the next request without a deploy.
 */
class SettingsService
{
    public const SETTINGS = [
        'ALLOW_REGISTRATION' => [
            'parameter' => 'app.allow_registration',
            'label' => 'Open registration',
            'help' => 'Show the /register page and the "Sign up" links. When off, only admins can create accounts.',
        ],
        'ALLOW_ANONYMOUS_UPLOADS' => [
            'parameter' => 'app.allow_anonymous_uploads',
            'label' => 'Anonymous uploads',
            'help' => 'Let visitors without an account upload through the home page (form, drop zone, mirror). Token-based remote uploads are unaffected.',
        ],
    ];

    public function __construct(
        private ContainerBagInterface $params,
        private string $projectDir
    ) {
    }

    public function getLocalEnvPath(): string
    {
        return $this->projectDir . '/.env.local';
    }

    /** Values the running application actually uses right now. */
    public function getEffectiveValues(): array
    {
        $values = [];
        foreach (self::SETTINGS as $key => $meta) {
            $values[$key] = (bool) $this->params->get($meta['parameter']);
        }
        return $values;
    }

    /** True when a compiled .env.local.php shadows the .env files (composer dump-env). */
    public function hasDumpedEnv(): bool
    {
        return is_file($this->projectDir . '/.env.local.php');
    }

    public function isWritable(): bool
    {
        $path = $this->getLocalEnvPath();
        return is_file($path) ? is_writable($path) : is_writable(dirname($path));
    }

    /** Rewrites only the managed keys in .env.local, preserving every other line. */
    public function save(array $values): void
    {
        $path = $this->getLocalEnvPath();
        $lines = is_file($path) ? preg_split('/\R/', (string) file_get_contents($path)) : [];
        if ($lines === [''] ) {
            $lines = [];
        }
        $seen = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=/', $line, $m) && array_key_exists($m[1], self::SETTINGS)) {
                $lines[$i] = $m[1] . '=' . (empty($values[$m[1]]) ? '0' : '1');
                $seen[$m[1]] = true;
            }
        }
        $header = false;
        foreach (self::SETTINGS as $key => $meta) {
            if (!isset($seen[$key])) {
                if (!$header) {
                    if ($lines && trim(end($lines)) !== '') {
                        $lines[] = '';
                    }
                    $lines[] = '# Managed from the admin settings page';
                    $header = true;
                }
                $lines[] = $key . '=' . (empty($values[$key]) ? '0' : '1');
            }
        }
        $content = rtrim(implode("\n", $lines)) . "\n";
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write {$path}");
        }
    }
}
