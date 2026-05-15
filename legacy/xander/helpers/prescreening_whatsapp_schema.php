<?php
declare(strict_types=1);

require_once __DIR__ . '/prescreening_schema.php';

function xander_ensure_prescreening_whatsapp_tables(mysqli $conn): void
{
    xander_ensure_prescreening_table($conn);

    $r = @$conn->query("SHOW COLUMNS FROM prescreening_submissions LIKE 'source'");
    if ($r && $r->num_rows === 0) {
        @$conn->query("ALTER TABLE prescreening_submissions ADD COLUMN source VARCHAR(16) NOT NULL DEFAULT 'admin' AFTER user_id");
    }

    $sqlSession = "CREATE TABLE IF NOT EXISTS whatsapp_prescreening_sessions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        wa_phone VARCHAR(20) NOT NULL,
        current_step VARCHAR(64) NOT NULL DEFAULT 'idle',
        answers_json MEDIUMTEXT NULL,
        doc_index INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_wa_phone (wa_phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!@$conn->query($sqlSession)) {
        error_log('[prescreening_whatsapp_schema] sessions table: ' . $conn->error);
    }

    $sqlDedup = "CREATE TABLE IF NOT EXISTS whatsapp_inbound_dedup (
        message_id VARCHAR(128) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!@$conn->query($sqlDedup)) {
        error_log('[prescreening_whatsapp_schema] dedup table: ' . $conn->error);
    }
}
