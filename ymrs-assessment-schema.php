<?php
$ymrs_schema_sql = "CREATE TABLE IF NOT EXISTS ymrs_assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    clinician_id INT NOT NULL,
    assessment_date DATE NOT NULL,
    total_score SMALLINT UNSIGNED NOT NULL,
    score_data LONGTEXT NOT NULL,
    clinical_notes TEXT NULL,
    demographic_data LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ymrs_patient_date (patient_id, assessment_date),
    INDEX idx_ymrs_clinician (clinician_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
$ymrs_schema_ready = $conn->query($ymrs_schema_sql);
