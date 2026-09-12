<?php

declare(strict_types=1);

namespace PlaudExample\Tests;

use PHPUnit\Framework\TestCase;
use Plaud\DTO\Recording;
use Plaud\DTO\RecordingDetail;
use PlaudExample\RecordingContent;
use PlaudExample\RecordingResponse;

final class RecordingResponseTest extends TestCase
{
    public function testDetailContractSeparatesSummaryContent(): void
    {
        $recording = RecordingDetail::fromArray([
            'file_id' => 'rec-1', 'file_name' => 'Meeting',
            'transcript' => 'Standard summary', 'summary' => 'Custom-template summary',
        ]);
        $response = RecordingResponse::detail($recording, RecordingContent::resolveCustomSummary($recording));
        self::assertSame(['id', 'filename', 'date', 'durationMinutes', 'summary', 'customSummary'], array_keys($response));
        self::assertSame('rec-1', $response['id']);
        self::assertSame('Standard summary', $response['summary']);
        self::assertSame('Custom-template summary', $response['customSummary']);
    }

    public function testMissingCustomSummaryStaysNullAndDownloadedContentIsKept(): void
    {
        $recording = RecordingDetail::fromArray(['transcript' => 'Standard only']);
        $response = RecordingResponse::detail($recording, null);
        self::assertSame('Standard only', $response['summary']);
        self::assertNull($response['customSummary']);
        self::assertSame('Downloaded template', RecordingResponse::detail($recording, 'Downloaded template')['customSummary']);
    }

    public function testListFlagsKeepTheirSeparateMeanings(): void
    {
        foreach ([[true, false], [false, true], [false, false], [true, true]] as [$standard, $custom]) {
            $recording = Recording::fromArray(['is_trans' => $standard, 'is_summary' => $custom]);
            $response = RecordingResponse::listItem($recording);
            self::assertSame($standard, $response['hasSummary']);
            self::assertSame($custom, $response['hasCustomSummary']);
            self::assertArrayNotHasKey('hasTranscript', $response);
        }
    }
}
