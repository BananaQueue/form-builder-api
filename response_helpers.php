<?php
/**
 * Shared SQL helpers for response/answer queries.
 */

/** Count only responses that have at least one answer row. */
function fb_response_count_expr(string $responseAlias = 'r'): string
{
    return "COUNT(DISTINCT CASE WHEN a.id IS NOT NULL THEN {$responseAlias}.id END)";
}

/** Delete response rows that have no linked answers. Returns rows deleted. */
function fb_delete_empty_responses(PDO $pdo, ?int $formId = null): int
{
    if ($formId !== null) {
        $stmt = $pdo->prepare("
            DELETE r
            FROM responses r
            LEFT JOIN answers a ON a.response_id = r.id
            WHERE a.id IS NULL AND r.form_id = ?
        ");
        $stmt->execute([$formId]);
    } else {
        $stmt = $pdo->query("
            DELETE r
            FROM responses r
            LEFT JOIN answers a ON a.response_id = r.id
            WHERE a.id IS NULL
        ");
    }

    return $stmt->rowCount();
}
