<?php

declare(strict_types=1);

namespace PlaudExample\Tests;

use Plaud\DTO\RecordingDetail;
use PlaudExample\RecordingContent;
use PHPUnit\Framework\TestCase;

final class RecordingContentTest extends TestCase
{
    public function testUsesDirectTranscriptBeforeOtherContent(): void
    {
        $recording = RecordingDetail::fromArray([
            'file_id' => 'recording-1',
            'transcript' => 'The transcript that matches the audio.',
            'pre_download_content_list' => [
                ['data_content' => 'A different generated version.'],
            ],
        ]);

        self::assertSame('The transcript that matches the audio.', RecordingContent::resolveTranscript($recording));
    }

    public function testResolvesNestedSummaryContent(): void
    {
        $recording = RecordingDetail::fromArray([
            'file_id' => 'recording-2',
            'summary' => '20260713161334-v2@summary-id',
            'extra_data' => [
                'ai_summary' => [
                    'content' => 'A clean summary of the conversation.',
                ],
            ],
        ]);

        self::assertSame('A clean summary of the conversation.', RecordingContent::resolveSummary($recording));
    }

    public function testIgnoresSummaryIdentifierWhenNoContentExists(): void
    {
        $recording = RecordingDetail::fromArray([
            'file_id' => 'recording-3',
            'summary' => '20260713161334-v2@summary-id',
        ]);

        self::assertNull(RecordingContent::resolveSummary($recording));
    }
}