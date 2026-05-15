<?php
declare(strict_types=1);

/**
 * Pre-screening submissions table (auto-created on first use).
 */
function xander_ensure_prescreening_table(mysqli $conn): void
{
    $sql = "CREATE TABLE IF NOT EXISTS prescreening_submissions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id VARCHAR(64) NOT NULL,
        source VARCHAR(16) NOT NULL DEFAULT 'admin',
        student_name VARCHAR(255) NOT NULL DEFAULT '',
        student_email VARCHAR(255) NOT NULL DEFAULT '',
        whatsapp_number VARCHAR(32) NOT NULL DEFAULT '',
        education_level VARCHAR(255) NOT NULL DEFAULT '',
        course_program VARCHAR(500) NOT NULL DEFAULT '',
        country_interest VARCHAR(255) NOT NULL DEFAULT '',
        open_other_countries TEXT NULL,
        budget_tuition VARCHAR(255) NOT NULL DEFAULT '',
        funds_application_visa VARCHAR(16) NOT NULL DEFAULT '',
        sponsor VARCHAR(64) NOT NULL DEFAULT '',
        afford_deposit VARCHAR(16) NOT NULL DEFAULT '',
        has_valid_passport VARCHAR(16) NOT NULL DEFAULT '',
        academic_docs_ready VARCHAR(64) NOT NULL DEFAULT '',
        english_level VARCHAR(64) NOT NULL DEFAULT '',
        english_test_taken VARCHAR(255) NOT NULL DEFAULT '',
        visa_denied VARCHAR(16) NOT NULL DEFAULT '',
        planned_intake VARCHAR(255) NOT NULL DEFAULT '',
        ready_to_apply VARCHAR(16) NOT NULL DEFAULT '',
        doc_valid_passport VARCHAR(512) NOT NULL DEFAULT '',
        doc_degree_transcripts VARCHAR(512) NOT NULL DEFAULT '',
        doc_high_school VARCHAR(512) NOT NULL DEFAULT '',
        doc_cv_resume VARCHAR(512) NOT NULL DEFAULT '',
        doc_recommendation VARCHAR(512) NOT NULL DEFAULT '',
        doc_personal_statement VARCHAR(512) NOT NULL DEFAULT '',
        doc_english_certificate VARCHAR(512) NOT NULL DEFAULT '',
        doc_birth_certificate VARCHAR(512) NOT NULL DEFAULT '',
        doc_payment_proof VARCHAR(512) NOT NULL DEFAULT '',
        submitted_by_admin_id INT UNSIGNED NULL DEFAULT NULL,
        email_sent TINYINT(1) NOT NULL DEFAULT 0,
        whatsapp_sent TINYINT(1) NOT NULL DEFAULT 0,
        notify_errors TEXT NULL,
        submitted_at DATETIME NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_prescreen_user (user_id),
        KEY idx_prescreen_submitted (submitted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!@$conn->query($sql)) {
        error_log('[prescreening_schema] CREATE TABLE failed: ' . $conn->error);
    }
}
