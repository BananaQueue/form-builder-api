<?php
require_once 'db.php';
require_once 'generate_form_code.php';

// Get all forms without codes
$stmt = $pdo->query("SELECT id, title FROM forms WHERE form_code IS NULL");
$forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;

foreach ($forms as $form) {
    $code = generateFormCodeWithSlug($pdo, $form['title']);
    
    $updateStmt = $pdo->prepare("UPDATE forms SET form_code = ? WHERE id = ?");
    $updateStmt->execute([$code, $form['id']]);
    
    echo "Form #{$form['id']}: '{$form['title']}' → {$code}<br>";
    $updated++;
}

echo "<br><strong>Updated $updated forms with unique codes.</strong>";
?>