<?php
/**
 * Resolve client question id -> new DB row id from the first-pass insert map.
 * JSON may encode ids as int, string digits, or temp strings ("q_…"); PHP array
 * keys are normalized for numeric ids, but this covers edge cases.
 */
function fb_question_map_get(array $map, $clientId): ?int
{
    if ($clientId === null || $clientId === '' || $clientId === false) {
        return null;
    }
    $candidates = [$clientId];
    if (is_numeric($clientId) && (is_string($clientId) || is_int($clientId) || is_float($clientId))) {
        $candidates[] = (int) $clientId;
        $candidates[] = (string) (int) $clientId;
    }
    foreach ($candidates as $k) {
        if (array_key_exists($k, $map)) {
            return (int) $map[$k];
        }
    }
    return null;
}
