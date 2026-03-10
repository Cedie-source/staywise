<?php
/**
 * Shared date filter utilities used by Reports, Announcements, etc.
 * Consolidates duplicated is_valid_ymd / compute_period functions.
 */

if (!function_exists('is_valid_ymd')) {
    /**
     * Validate a Y-m-d date string.
     */
    function is_valid_ymd(string $s): bool {
        if (!$s) return false;
        $d = DateTime::createFromFormat('Y-m-d', $s);
        return $d && $d->format('Y-m-d') === $s;
    }
}

if (!function_exists('compute_period')) {
    /**
     * Compute start/end date strings for a named period.
     * @return array{0: string, 1: string}|null  [start, end] or null if unknown key
     */
    function compute_period(string $periodKey): ?array {
        $today = new DateTime('today');
        switch ($periodKey) {
            case 'today':
                return [$today->format('Y-m-d'), $today->format('Y-m-d')];
            case 'yesterday':
                $y = (clone $today)->modify('-1 day');
                return [$y->format('Y-m-d'), $y->format('Y-m-d')];
            case 'last7':
                return [(clone $today)->modify('-6 days')->format('Y-m-d'), $today->format('Y-m-d')];
            case 'last30':
                return [(clone $today)->modify('-29 days')->format('Y-m-d'), $today->format('Y-m-d')];
            case 'thisMonth':
                return [$today->format('Y-m-01'), $today->format('Y-m-t')];
            default:
                return null;
        }
    }
}

if (!function_exists('build_date_where')) {
    /**
     * Build a WHERE clause for date filtering.
     * @return array{0: string, 1: array, 2: string}  [sql_fragment, params, types]
     */
    function build_date_where(string $col, string $dateFilter, ?array $periodRange): array {
        if ($periodRange) {
            return ["WHERE DATE($col) BETWEEN ? AND ?", [$periodRange[0], $periodRange[1]], 'ss'];
        }
        if (is_valid_ymd($dateFilter)) {
            return ["WHERE DATE($col) = ?", [$dateFilter], 's'];
        }
        return ['', [], ''];
    }
}
