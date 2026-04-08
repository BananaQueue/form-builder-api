<?php
function generateUniqueFormCode($pdo, $length = 8) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $maxAttempts = 10;
    
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        // Generate random code
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        // Check if code already exists
        $stmt = $pdo->prepare("SELECT id FROM forms WHERE form_code = ?");
        $stmt->execute([$code]);
        
        if (!$stmt->fetch()) {
            return $code; // Code is unique
        }
    }
    
    // If we couldn't find a unique code, try with longer length
    return generateUniqueFormCode($pdo, $length + 2);
}

// ADD THIS NEW FUNCTION
function generateSlugFromTitle($title) {
    // Convert to lowercase
    $slug = strtolower($title);
    
    // Replace spaces and special characters with hyphens
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    
    // Remove leading/trailing hyphens
    $slug = trim($slug, '-');
    
    // Limit length to 50 characters
    if (strlen($slug) > 50) {
        $slug = substr($slug, 0, 50);
        // Remove incomplete word at end
        $lastHyphen = strrpos($slug, '-');
        if ($lastHyphen !== false) {
            $slug = substr($slug, 0, $lastHyphen);
        }
    }
    
    // If slug is empty, use default
    if (empty($slug)) {
        $slug = 'form';
    }
    
    return $slug;
}

// ADD THIS FUNCTION TO COMBINE SLUG + CODE
function generateFormCodeWithSlug($pdo, $title, $codeLength = 7) {
    $slug = generateSlugFromTitle($title);
    $uniqueCode = generateUniqueFormCode($pdo, $codeLength);
    
    // Combine: slug-code
    return $slug . '-' . $uniqueCode;
}
?>