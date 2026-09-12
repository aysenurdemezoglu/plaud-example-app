<?php

declare(strict_types=1);

namespace PlaudExample\Tests;

use Plaud\DTO\RecordingDetail;
use PlaudExample\RecordingContent;
use PHPUnit\Framework\TestCase;

final class RecordingContentTest extends TestCase
{
    public function testUsesSdkStandardSummary(): void
    {
        $recording = RecordingDetail::fromArray([
            'file_id' => 'recording-1',
            'transcript' => 'Standard summary.',
            'pre_download_content_list' => [
                ['data_content' => 'A longer standard summary selected by the SDK.'],
            ],
        ]);

        self::assertSame('A longer standard summary selected by the SDK.', RecordingContent::resolveSummary($recording));
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

        self::assertSame('A clean summary of the conversation.', RecordingContent::resolveCustomSummary($recording));
    }

    public function testIgnoresSummaryIdentifierWhenNoContentExists(): void
    {
        $recording = RecordingDetail::fromArray([
            'file_id' => 'recording-3',
            'summary' => '20260713161334-v2@summary-id',
        ]);

        self::assertNull(RecordingContent::resolveCustomSummary($recording));
    }

    public function testUsesOnlyCustomTemplateLinks(): void
    {
        $recording = RecordingDetail::fromArray([
            'file_id' => 'recording-4',
            'content_list' => [
                ['data_type' => 'auto_sum_note', 'data_link' => 'https://example.test/auto-summary'],
                ['data_type' => 'transaction', 'data_link' => 'https://example.test/transcript'],
                ['data_type' => 'consumer_note', 'data_link' => 'https://example.test/consumer-summary'],
            ],
        ]);

        self::assertSame(
            ['https://example.test/consumer-summary'],
            RecordingContent::customSummaryLinks($recording)
        );
    }
    public function testMissingCustomSummaryDoesNotFallBackToStandardSummary(): void
    {
        $recording = RecordingDetail::fromArray(['transcript' => 'Standard summary only']);
        self::assertSame('Standard summary only', RecordingContent::resolveSummary($recording));
        self::assertNull(RecordingContent::resolveCustomSummary($recording));
    }

    public function testSeparateSummariesAndEmptyContent(): void
    {
        $recording = RecordingDetail::fromArray([
            'transcript' => 'Standard summary',
            'summary' => 'Custom-template summary',
        ]);
        self::assertSame('Standard summary', RecordingContent::resolveSummary($recording));
        self::assertSame('Custom-template summary', RecordingContent::resolveCustomSummary($recording));
        $empty = RecordingDetail::fromArray([]);
        self::assertSame('', RecordingContent::resolveSummary($empty));
        self::assertNull(RecordingContent::resolveCustomSummary($empty));
    }

    public function testMalformedLinksAndStandardOnlyLinksAreIgnored(): void
    {
        $recording = RecordingDetail::fromArray(['content_list' => [
            null, 'invalid', [],
            ['data_type' => 'auto_sum_note', 'data_link' => 'https://example.test/standard'],
            ['data_type' => 'consumer_note', 'data_link' => ''],
        ]]);
        self::assertSame([], RecordingContent::customSummaryLinks($recording));
    }
}