<?php

declare(strict_types=1);

namespace PlaudExample;

use Plaud\DTO\RecordingDetail;

final class RecordingContent
{
    public static function resolveSummary(RecordingDetail $recording): string
    {
        // Support the installed legacy SDK and the renamed SDK during migration.
        return property_exists($recording, 'customSummary')
            ? $recording->summary
            : $recording->transcript;
    }

    public static function resolveCustomSummary(RecordingDetail $recording): ?string
    {
        $preferredSummary = self::findTextByKeys($recording->raw, ['ai_summary', 'ai_summary_text', 'summary_text']);
        if ($preferredSummary !== null && !self::isSummaryIdentifier($preferredSummary)) {
            return $preferredSummary;
        }

        $sdkSummary = property_exists($recording, 'customSummary')
            ? $recording->customSummary
            : $recording->summary;
        $summary = self::findSummary($recording->raw) ?? $sdkSummary;
        if ($summary === null || trim($summary) === '' || self::isSummaryIdentifier(trim($summary))) {
            return null;
        }

        return $summary;
    }

    /**
     * @return list<string>
     */
    public static function customSummaryLinks(RecordingDetail $recording): array
    {
        $contentList = is_array($recording->raw['content_list'] ?? null) ? $recording->raw['content_list'] : [];
        $links = [];
        foreach ($contentList as $item) {
            if (!is_array($item) || ($item['data_type'] ?? '') !== 'consumer_note') {
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
