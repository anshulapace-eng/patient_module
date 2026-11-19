<?php
require 'config.php';
require 'assessment-demographics.php';

$tables = [
    'bprs_assessments' => 'clinical_notes',
    'panss_assessments' => 'clinical_notes',
    'ace_iii_assessments' => 'clinical_notes',
    'assessment_responses' => 'assessment_date',
];

foreach ($tables as $table => $after) {
    if (!assessment_demographics_ensure_column($conn, $table, $after)) {
        fwrite(STDERR, "Migration failed: {$table}\n");
        exit(1);
    }
    $check = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'demographic_data'");
    if (!$check || $check->num_rows !== 1) {
        fwrite(STDERR, "Column verification failed: {$table}\n");
        exit(1);
    }
    $check->free();
    echo "OK: {$table}.demographic_data\n";
}
