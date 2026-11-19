<?php
$ace_iii_schema_ready = $conn->query(
    "CREATE TABLE IF NOT EXISTS ace_iii_assessments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        clinician_id INT NOT NULL DEFAULT 0,
        assessment_date DATE NOT NULL,
        total_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        attention_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        memory_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        fluency_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        language_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        visuospatial_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        patient_details LONGTEXT NOT NULL,
        response_data LONGTEXT NOT NULL,
        clinical_notes TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ace_patient_date (patient_id, assessment_date),
        INDEX idx_ace_clinician (clinician_id),
        INDEX idx_ace_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

if ($ace_iii_schema_ready) {
    $status_check = $conn->query("SHOW COLUMNS FROM ace_iii_assessments LIKE 'status'");
    if ($status_check && $status_check->num_rows === 0) {
        $conn->query(
            "ALTER TABLE ace_iii_assessments
             ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Pending' AFTER clinical_notes"
        );
    }
    $status_check->free();

    $demographic_check = $conn->query("SHOW COLUMNS FROM ace_iii_assessments LIKE 'demographic_data'");
    if ($demographic_check && $demographic_check->num_rows === 0) {
        $conn->query("ALTER TABLE ace_iii_assessments ADD COLUMN demographic_data LONGTEXT NULL AFTER clinical_notes");
    }
    if ($demographic_check) {
        $demographic_check->free();
    }
}

if (!$ace_iii_schema_ready) {
    error_log('ACE-III table initialization failed: ' . $conn->error);
}
