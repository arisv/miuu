<?php

namespace App\Service;


use App\Entity\StoredFile;
use App\Entity\UploadRecord;
use Symfony\Component\HttpFoundation\Request;

class CursorService
{
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
        $data = json_decode(base64_decode($cursor), true);
        if (!$data) {
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