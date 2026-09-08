<?php

declare(strict_types=1);

namespace PlaudExample;

use Plaud\DTO\RecordingDetail;

final class RecordingContent
{
    public static function resolveTranscript(RecordingDetail $recording): string
    {
        $directTranscript = self::findTextByKeys($recording->raw, ['transcript', 'transcript_text']);
        if ($directTranscript !== null) {
            return $directTranscript;
        }

        foreach ($recording->raw as $value) {
            if (!is_array($value)) {
                continue;
            }
            foreach ($value as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $type = strtolower((string) ($item['type'] ?? $item['content_type'] ?? $item['name'] ?? ''));
                if (str_contains($type, 'transcript') && isset($item['data_content']) && is_string($item['data_content'])) {
                    return trim($item['data_content']);
                }
            }
        }

        return $recording->transcript;
    }

    public static function resolveSummary(RecordingDetail $recording): ?string
    {
        $preferredSummary = self::findTextByKeys($recording->raw, ['ai_summary', 'ai_summary_text', 'summary_text']);
        if ($preferredSummary !== null && !self::isSummaryIdentifier($preferredSummary)) {
            return $preferredSummary;
        }

        $summary = self::findSummary($recording->raw) ?? $recording->summary;
        if ($summary === null || self::isSummaryIdentifier($summary)) {
            return null;
        }

        return $summary;
    }

    /**
     * @return list<string>
     */
    public static function summaryLinks(RecordingDetail $recording): array
    {
        $contentList = is_array($recording->raw['content_list'] ?? null) ? $recording->raw['content_list'] : [];
        usort($contentList, static function (array $first, array $second): int {
            $priority = ['consumer_note' => 0, 'auto_sum_note' => 1];
            return ($priority[$first['data_type'] ?? ''] ?? 2) <=> ($priority[$second['data_type'] ?? ''] ?? 2);
        });

        $links = [];
        foreach ($contentList as $item) {
            if (!is_array($item) || !in_array($item['data_type'] ?? '', ['consumer_note', 'auto_sum_note'], true)) {
                continue;
            }
            if (isset($item['data_link']) && is_string($item['data_link']) && $item['data_link'] !== '') {
                $links[] = $item['data_link'];
            }
        }

        return $links;
    }

    private static function findTextByKeys(mixed $value, array $keys): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        foreach ($keys as $key) {
            if (isset($value[$key]) && is_string($value[$key]) && trim($value[$key]) !== '') {
                return trim($value[$key]);
            }
        }

        foreach ($value as $item) {
            $result = self::findTextByKeys($item, $keys);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    private static function findSummary(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $key => $item) {
            $normalizedKey = strtolower((string) $key);
            if (str_contains($normalizedKey, 'summary')) {
                if (is_string($item) && trim($item) !== '' && !self::isSummaryIdentifier(trim($item))) {
                    return trim($item);
                }
                $nestedSummary = self::findTextByKeys($item, ['content', 'text', 'value', 'data_content']);
                if ($nestedSummary !== null && !self::isSummaryIdentifier($nestedSummary)) {
                    return $nestedSummary;
                }
            }

            $nestedResult = self::findSummary($item);
            if ($nestedResult !== null) {
                return $nestedResult;
            }
        }

        return null;
    }

    private static function isSummaryIdentifier(string $value): bool
    {
        return preg_match('/^\d{8,}-v\d+@/i', $value) === 1;
    }
}
