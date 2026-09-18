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

    public function getOrderFromRequest(Request $request)
    {
        $result = [];
        $orderDate = $request->query->get('order-date');
        $orderSize = $request->query->get('order-size');

        if ($orderDate == 'new') {
            $result['file.date'] = [
                'op' => '<',
                'order' => 'DESC'
            ];
        }
        if ($orderDate == "old") {
            $result['file.date'] = [
                'op' => '>',
                'order' => 'ASC'
            ];
        }
        if ($orderSize == 'big') {
            $result['file.internalSize'] = [
                'op' => '<',
                'order' => 'DESC'
            ];
        }
        if ($orderSize == "small") {
            $result['file.internalSize'] = [
                'op' => '>',
                'order' => 'ASC'
            ];
        }

        return $result;
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

    public function decodeCursor($cursor)
    {
        $data = json_decode(base64_decode((string) $cursor, true) ?: '', true);
        // A malformed or foreign cursor degrades to the first page instead of warning.
        if (!is_array($data) || !isset($data['s'], $data['i'], $data['d'])) {
            return [];
        }
        return [
            'file.internalSize' => $data['s'],
            'log.uploadId' => $data['i'],
            'file.id' => $data['i'],
            'file.date' => $data['d']
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
            'd' => $file->getDate()
        ];
        return base64_encode(json_encode($data));
    }

}