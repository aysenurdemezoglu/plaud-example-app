<?php

declare(strict_types=1);

namespace PlaudExample;

use Plaud\DTO\Recording;
use Plaud\DTO\RecordingDetail;

final class RecordingResponse
{
    public static function listItem(Recording $recording): array
    {
        return [
            'id' => $recording->id,
            'filename' => $recording->filename,
            'date' => $recording->getFormattedStartDate('M j, Y / H:i'),
            'dateKey' => $recording->getFormattedStartDate('Y-m-d'),
            'durationMinutes' => $recording->getDurationMinutes(),
            'hasSummary' => $recording->isTrans,
            'hasCustomSummary' => $recording->isSummary,
        ];
    }

    public static function detail(RecordingDetail $recording, ?string $customSummary): array
    {
        return [
            'id' => $recording->id,
            'filename' => $recording->filename,
            'date' => $recording->getFormattedStartDate('M j, Y / H:i'),
            'durationMinutes' => $recording->getDurationMinutes(),
            'summary' => RecordingContent::resolveSummary($recording),
            'customSummary' => $customSummary,
        ];
    }
}
