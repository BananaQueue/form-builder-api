<?php
/**
 * Shared helpers for fetching form questions with compatibility fallbacks.
 */

function fb_get_question_columns(PDO $pdo): array
{
    $stmt = $pdo->query("SHOW COLUMNS FROM questions");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $questionColumns = [];
    foreach ($columns as $column) {
        $questionColumns[$column['Field']] = true;
    }
    return $questionColumns;
}

function fb_build_question_select_sql(array $questionColumns): string
{
    $questionSelectColumns = [
        "id",
        "question_text",
        "question_type",
        isset($questionColumns['rating_scale']) ? "rating_scale" : "NULL AS rating_scale",
        isset($questionColumns['number_min']) ? "number_min" : "NULL AS number_min",
        isset($questionColumns['number_max']) ? "number_max" : "NULL AS number_max",
        isset($questionColumns['number_step']) ? "number_step" : "NULL AS number_step",
        isset($questionColumns['datetime_type']) ? "datetime_type" : "NULL AS datetime_type",
        "position",
        isset($questionColumns['is_required']) ? "is_required" : "1 AS is_required",
        isset($questionColumns['condition_question_id']) ? "condition_question_id" : "NULL AS condition_question_id",
        isset($questionColumns['condition_type']) ? "condition_type" : "'equals' AS condition_type",
        isset($questionColumns['condition_value']) ? "condition_value" : "NULL AS condition_value",
    ];
    return implode(",\n            ", $questionSelectColumns);
}

function fb_fetch_questions_with_options(PDO $pdo, int $formId, string $questionSelectSql): array
{
    $questionStmt = $pdo->prepare("
        SELECT
            {$questionSelectSql}
        FROM questions
        WHERE form_id = ?
        ORDER BY position ASC
    ");
    $questionStmt->execute([$formId]);
    $questions = $questionStmt->fetchAll(PDO::FETCH_ASSOC);

    $optionStmt = $pdo->prepare("
        SELECT
            option_text,
            position
        FROM question_options
        WHERE question_id = ?
        ORDER BY position ASC
    ");

    foreach ($questions as &$question) {
        $optionStmt->execute([$question['id']]);
        $options = $optionStmt->fetchAll(PDO::FETCH_ASSOC);
        $question['options'] = array_map(function ($opt) {
            return $opt['option_text'];
        }, $options);
    }
    unset($question);

    return $questions;
}
