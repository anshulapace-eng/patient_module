<?php
$panss_schema_sql = "CREATE TABLE IF NOT EXISTS panss_assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    clinician_id INT NOT NULL,
    assessment_date DATE NOT NULL,
    positive_score SMALLINT UNSIGNED NOT NULL,
    negative_score SMALLINT UNSIGNED NOT NULL,
    general_score SMALLINT UNSIGNED NOT NULL,
    composite_score SMALLINT NOT NULL,
    score_data LONGTEXT NOT NULL,
    clinical_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_panss_patient_date (patient_id, assessment_date),
    INDEX idx_panss_clinician (clinician_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
$panss_schema_ready = $conn->query($panss_schema_sql);

if (!$panss_schema_ready) {
    error_log('PANSS table initialization failed: ' . $conn->error);
}
