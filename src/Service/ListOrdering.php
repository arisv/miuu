<?php

namespace App\Service;

use App\Entity\StoredFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sorting and grouping of file listings, shared by the web gallery, the JSON API and the
 * "load more" endpoints.
 *
 * Query parameters: `sort` (date|size|type|name), `order` (asc|desc), `group` (none|date|az|type|size).
 * The legacy `order-date=new|old` and `order-size=big|small` pairs still map onto them.
 *
 * Grouping is implemented as ordering: the group key becomes the leading ORDER BY expression, so
 * every page arrives grouped and clients only have to draw a header where the key changes. The
 * group order follows `order` as well (descending = newest month / Z / biggest bucket first).
 */
class ListOrdering
{
    public const SORTS = ['date', 'size', 'type', 'name'];
    public const GROUPS = ['none', 'date', 'az', 'type', 'size'];
    public const ORDERS = ['asc', 'desc'];

    public const DEFAULT_SORT = 'date';
    public const DEFAULT_ORDER = 'desc';
    public const DEFAULT_GROUP = 'none';

    /** Size buckets (upper bounds, bytes) for "group by size"; the last bucket is open-ended. */
    public const SIZE_BUCKETS = [1048576, 10485760, 104857600, 1073741824];
    public const SIZE_LABELS = ['Under 1 MB', '1 – 10 MB', '10 – 100 MB', '100 MB – 1 GB', 'Over 1 GB'];

    /** Type buckets, in the order of {@see StoredFile::previewKind()}. */
    public const TYPE_KINDS = ['image', 'video', 'audio', 'text', 'file'];
    public const TYPE_LABELS = ['Images', 'Videos', 'Audio', 'Text', 'Other files'];

    /** Mime types that count as text besides text/*; keep in sync with StoredFile::isTextual(). */
    private const TEXTUAL_MIMES = [
        'application/json', 'application/ld+json', 'application/xml', 'application/javascript', 'application/x-javascript',
        'application/x-yaml', 'application/yaml', 'application/x-sh', 'application/x-shellscript', 'application/x-httpd-php',
        'application/x-php', 'application/toml', 'application/sql', 'application/x-empty',
    ];

    public static function fromRequest(Request $request): ListOrder
    {
        $q = $request->query;
        $sort = (string) $q->get('sort', '');
        $order = (string) $q->get('order', '');
        $group = (string) $q->get('group', '');

        if (!in_array($sort, self::SORTS, true)) {
            // Legacy controls: size wins over date when both are present, as the user asked for a size order.
            $legacySize = $q->get('order-size');
            $legacyDate = $q->get('order-date');
            if ($legacySize === 'big' || $legacySize === 'small') {
                $sort = 'size';
                $order = $legacySize === 'big' ? 'desc' : 'asc';
            } elseif ($legacyDate === 'new' || $legacyDate === 'old') {
                $sort = 'date';
                $order = $legacyDate === 'new' ? 'desc' : 'asc';
            } else {
                $sort = self::DEFAULT_SORT;
            }
        }
        if (!in_array($order, self::ORDERS, true)) {
            $order = self::DEFAULT_ORDER;
        }
        if (!in_array($group, self::GROUPS, true)) {
            $group = self::DEFAULT_GROUP;
        }
        return new ListOrder($sort, $order, $group);
    }

    /**
     * ORDER BY keys (before the tie-breaker): [['expr' => DQL, 'key' => cursor field], ...].
     * The file entity must be aliased `file`.
     */
    public static function orderKeys(ListOrder $order): array
    {
        $keys = [];
        if ($order->group !== 'none' && !self::groupIsImpliedBySort($order)) {
            $keys[] = ['expr' => self::groupExpression($order->group), 'key' => 'group'];
        }
        $keys[] = ['expr' => self::sortExpression($order->sort), 'key' => 'sort'];
        return $keys;
    }

    /** Group key and sort key values for a file, matching the DQL expressions above. */
    public static function cursorValues(ListOrder $order, array $raw): array
    {
        return [
            'group' => $order->group === 'none' ? null : self::groupValueFromRaw($order->group, $raw),
            'sort' => match ($order->sort) {
                'size' => (int) $raw['s'],
                'type' => mb_strtolower((string) $raw['e']),
                'name' => (string) $raw['n'],
                default => (int) $raw['d'],
            },
        ];
    }

    /** Human label of the group a file falls in, or null when not grouping. */
    public static function groupLabel(StoredFile $file, string $group): ?string
    {
        return match ($group) {
            'date' => gmdate('F Y', (int) $file->getDate()),
            'az' => self::letterBucket($file->getOriginalName()),
            'type' => self::TYPE_LABELS[array_search($file->previewKind(), self::TYPE_KINDS, true) ?: 0],
            'size' => self::SIZE_LABELS[self::sizeBucket((int) $file->getInternalSize())],
            default => null,
        };
    }

    private static function groupIsImpliedBySort(ListOrder $order): bool
    {
        // Sorting by the same field already keeps the buckets contiguous; skip the redundant key.
        return ($order->group === 'date' && $order->sort === 'date')
            || ($order->group === 'size' && $order->sort === 'size');
    }

    private static function sortExpression(string $sort): string
    {
        return match ($sort) {
            'size' => 'file.internalSize',
            'type' => 'LOWER(file.originalExtension)',
            'name' => 'file.originalName',
            default => 'file.date',
        };
    }

    private static function groupExpression(string $group): string
    {
        switch ($group) {
            case 'date':
                return 'MONTH_KEY(file.date)';
            case 'az':
                $first = 'UPPER(SUBSTRING(file.originalName, 1, 1))';
                return "CASE WHEN $first BETWEEN 'A' AND 'Z' THEN $first ELSE '#' END";
            case 'type':
                $textual = implode(', ', array_map(fn ($m) => "'$m'", self::TEXTUAL_MIMES));
                return "CASE WHEN file.internalMimetype LIKE 'image/%' THEN 0"
                    . " WHEN file.internalMimetype LIKE 'video/%' THEN 1"
                    . " WHEN file.internalMimetype LIKE 'audio/%' THEN 2"
                    . " WHEN (file.internalMimetype LIKE 'text/%' OR LOWER(file.internalMimetype) IN ($textual)) THEN 3"
                    . ' ELSE 4 END';
            case 'size':
                [$a, $b, $c, $d] = self::SIZE_BUCKETS;
                return "CASE WHEN file.internalSize < $a THEN 0 WHEN file.internalSize < $b THEN 1"
                    . " WHEN file.internalSize < $c THEN 2 WHEN file.internalSize < $d THEN 3 ELSE 4 END";
        }
        throw new \InvalidArgumentException("Unknown group $group");
    }

    private static function groupValueFromRaw(string $group, array $raw): string|int
    {
        return match ($group) {
            'date' => gmdate('Ym', (int) $raw['d']),
            'az' => self::letterBucket((string) $raw['n']),
            'type' => self::typeBucket((string) $raw['m']),
            'size' => self::sizeBucket((int) $raw['s']),
        };
    }

    public static function sizeBucket(int $size): int
    {
        foreach (self::SIZE_BUCKETS as $i => $bound) {
            if ($size < $bound) {
                return $i;
            }
        }
        return count(self::SIZE_BUCKETS);
    }

    public static function typeBucket(string $mime): int
    {
        $mime = strtolower($mime);
        if (str_starts_with($mime, 'image/')) {
            return 0;
        }
        if (str_starts_with($mime, 'video/')) {
            return 1;
        }
        if (str_starts_with($mime, 'audio/')) {
            return 2;
        }
        if (str_starts_with($mime, 'text/') || in_array($mime, self::TEXTUAL_MIMES, true)) {
            return 3;
        }
        return 4;
    }

    /**
     * First letter A–Z, '#' for anything else. Accented letters are folded to their base letter,
     * which is how the database's accent-insensitive collation compares them.
     */
    public static function letterBucket(string $name): string
    {
        $first = mb_strtoupper(mb_substr($name, 0, 1));
        if ($first === '') {
            return '#';
        }
        if (!preg_match('/^[A-Z]$/', $first)) {
            $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $first);
            $first = $folded !== false ? strtoupper(substr($folded, 0, 1)) : '';
        }
        return preg_match('/^[A-Z]$/', $first) ? $first : '#';
    }
}

/** Parsed sort/order/group triple. */
final class ListOrder
{
    public function __construct(
        public readonly string $sort,
        public readonly string $order,
        public readonly string $group,
    ) {
    }

    public function descending(): bool
    {
        return $this->order === 'desc';
    }

    public function toArray(): array
    {
        return ['sort' => $this->sort, 'order' => $this->order, 'group' => $this->group];
    }
}
