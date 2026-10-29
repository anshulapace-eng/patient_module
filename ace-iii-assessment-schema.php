<?php
$ace_iii_schema_ready = $conn->query(
    "CREATE TABLE IF NOT EXISTS ace_iii_assessments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        clinician_id INT NOT NULL,
        assessment_date DATE NOT NULL,
        total_score SMALLINT UNSIGNED NOT NULL,
        attention_score SMALLINT UNSIGNED NOT NULL,
        memory_score SMALLINT UNSIGNED NOT NULL,
        fluency_score SMALLINT UNSIGNED NOT NULL,
        language_score SMALLINT UNSIGNED NOT NULL,
        visuospatial_score SMALLINT UNSIGNED NOT NULL,
        patient_details LONGTEXT NOT NULL,
        response_data LONGTEXT NOT NULL,
        clinical_notes TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ace_patient_date (patient_id, assessment_date),
        INDEX idx_ace_clinician (clinician_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

if (!$ace_iii_schema_ready) {
    error_log('ACE-III table initialization failed: ' . $conn->error);
}
