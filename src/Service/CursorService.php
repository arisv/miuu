<?php

namespace App\Service;


use App\Entity\StoredFile;
use App\Entity\UploadRecord;
use Symfony\Component\HttpFoundation\Request;

class CursorService
{
    public const DEFAULT_PAGE_SIZE = 24;
    public const PAGE_SIZES = [24, 48, 72, 96];

    /**
     * Page size from the "page-size" query parameter, restricted to the allowed multiples of 24.
     */
    public function getPageSizeFromRequest(Request $request): int
    {
        $requested = (int) $request->query->get('page-size');
        return in_array($requested, self::PAGE_SIZES, true) ? $requested : self::DEFAULT_PAGE_SIZE;
    }

    /** Sort / order / group from the query string (legacy order-date / order-size still accepted). */
    public function getOrderFromRequest(Request $request): ListOrder
    {
        return ListOrdering::fromRequest($request);
    }

    public function getFilterFromRequest(Request $request)
    {
        $calendarStart = \DateTime::createFromFormat('Y-m-d', $request->query->get('calendar-start'));
        $calendarEnd = \DateTime::createFromFormat('Y-m-d', $request->query->get('calendar-end'));

        $result = [];

        if ($calendarStart && $calendarEnd) {
            $calendar = [$calendarStart, $calendarEnd];
            sort($calendar);
            $result['calendar'] = $calendar;
        }

        return $result;
    }

    /**
     * Raw column values of the last row of the previous page, or [] for the first page. The
     * repository turns them into keyset comparisons for whatever ordering is active, so a cursor
     * only has to travel together with the same sort/order/group and filter.
     */
    public function decodeCursor($cursor): array
    {
        $data = json_decode(base64_decode((string) $cursor, true) ?: '', true);
        // A malformed, foreign or pre-ordering cursor degrades to the first page instead of warning.
        if (!is_array($data) || !isset($data['s'], $data['i'], $data['d'], $data['n'], $data['e'], $data['m'])) {
            return [];
        }
        return [
            's' => (int) $data['s'],
            'i' => (int) $data['i'],
            'd' => (int) $data['d'],
            'n' => (string) $data['n'],
            'e' => (string) $data['e'],
            'm' => (string) $data['m'],
        ];
    }

    /**
     * Accepts an upload record (user history, tie-breaker is the record id)
     * or a bare stored file (anonymous history, tie-breaker is the file id).
     */
    public function encodeCursor(UploadRecord|StoredFile $record)
    {
        if ($record instanceof UploadRecord) {
            $file = $record->getImage();
            $tieBreaker = $record->getUploadId();
        } else {
            $file = $record;
            $tieBreaker = $record->getId();
        }
        $data = [
            's' => $file->getInternalSize(),
            'i' => $tieBreaker,
            'd' => $file->getDate(),
            'n' => $file->getOriginalName(),
            'e' => $file->getOriginalExtension(),
            'm' => $file->getInternalMimetype(),
        ];
        return base64_encode(json_encode($data));
    }

}