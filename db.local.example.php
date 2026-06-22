<?php
// Copy this file to db.local.php for local development or E2E testing.
// db.local.php is intentionally ignored by git because it may contain secrets.
return [
    'host' => 'localhost',
    'dbname' => 'form_builder_test',
    'username' => 'root',
    'password' => '',
    'timezone' => '+08:00',
    'allow_test_guard' => true,
    'test_reset_token' => 'local-e2e-reset',
];
