<?php

/**
 * Shared office-days listing for API + Excel export.
 */

function office_parse_date(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    return $dt instanceof DateTimeImmutable ? $dt : null;
}

/**
 * @return array{
 *   start: DateTimeImmutable,
 *   end: DateTimeImmutable,
 *   startLabel: string,
 *   endLabel: string,
 *   rows: list<array{
 *     email: string,
 *     name: string,
 *     hasDataInRange: bool,
 *     officeDays: int,
 *     missingDays: list<string>,
 *     warning: string
 *   }>
 * }
 */
function office_days_collect(?string $startRaw, ?string $endRaw): array
{
    $today = new DateTimeImmutable('today');
    $start = office_parse_date($startRaw) ?? $today->modify('first day of this month');
    $end = office_parse_date($endRaw) ?? $today;
    if ($end < $start) {
        $end = $start;
    }

    $rows = [];
    foreach (hours_list_users_with_data() as $email) {
        $data = hours_load_existing($email);
        if ($data === null) {
            continue;
        }
        $name = hours_resolve_user_name($data, $email);
        $hasDataInRange = hours_has_any_day_in_range($data, $start, $end);
        $missing = [];
        if (!janus_is_light_mode($email)) {
            $missing = hours_missing_days_in_range($data, $start, $end);
        }
        $missingLabels = [];
        foreach ($missing as $iso) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', (string) $iso);
            if ($dt instanceof DateTimeImmutable) {
                $missingLabels[] = janus_nl_date_ui($dt);
            }
        }
        $rows[] = [
            'email' => $email,
            'name' => $name,
            'hasDataInRange' => $hasDataInRange,
            'officeDays' => hours_count_office_days_in_range($data, $start, $end),
            'missingDays' => $missingLabels,
            'warning' => $missingLabels === [] ? '' : ('Ontbrekende dagen: ' . implode(', ', $missingLabels)),
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $aHas = !empty($a['hasDataInRange']);
        $bHas = !empty($b['hasDataInRange']);
        if ($aHas !== $bHas) {
            return $aHas ? -1 : 1;
        }

        return strcasecmp($a['name'], $b['name']) ?: strcasecmp($a['email'], $b['email']);
    });

    return [
        'start' => $start,
        'end' => $end,
        'startLabel' => janus_nl_date_ui($start),
        'endLabel' => janus_nl_date_ui($end),
        'rows' => $rows,
    ];
}
