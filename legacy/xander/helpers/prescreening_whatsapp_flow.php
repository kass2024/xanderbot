<?php
/**
 * Student pre-screening via WhatsApp (conversational flow).
 * Trigger: student sends PRESCREENING (or prescreening / pre-screening).
 * On completion: saves submission and notifies staff numbers from .env.
 */
declare(strict_types=1);

require_once __DIR__ . '/env_load.php';
require_once __DIR__ . '/student_status_notify.php';
require_once __DIR__ . '/prescreening_notify.php';
require_once __DIR__ . '/prescreening_whatsapp_schema.php';

const XANDER_PRESCREENING_TRIGGERS = ['prescreening', 'pre-screening', 'prescreen', 'screening', 'start screening'];

/** Meta template: admin invites student from sidebar — body {{1}} = student name */
const XANDER_WHATSAPP_PRESCREENING_INVITE_TEMPLATE = 'xander_prescreening_invite';
/** Match the language you chose in Meta (English → often en or en_US). */
const XANDER_WHATSAPP_PRESCREENING_INVITE_TEMPLATE_LANG = 'en_US';

function xander_prescreening_invite_template_name(): string
{
    xander_load_env_file();
    $n = trim((string) xander_env_get('WHATSAPP_PRESCREENING_INVITE_TEMPLATE'));

    return $n !== '' ? $n : XANDER_WHATSAPP_PRESCREENING_INVITE_TEMPLATE;
}

function xander_prescreening_invite_template_lang(): string
{
    xander_load_env_file();
    $n = trim((string) xander_env_get('WHATSAPP_PRESCREENING_INVITE_TEMPLATE_LANG'));

    return $n !== '' ? $n : XANDER_WHATSAPP_PRESCREENING_INVITE_TEMPLATE_LANG;
}

/** @return array<int, array{key:string,prompt:string,hint?:string}> */
function xander_prescreening_whatsapp_question_steps(): array
{
    return [
        ['key' => 'student_name', 'prompt' => "What is your *full name*?"],
        ['key' => 'student_email', 'prompt' => "What is your *email address*? (Type *skip* if none)"],
        ['key' => 'education_level', 'prompt' => "1/15 — What is your *highest level of education*?"],
        ['key' => 'course_program', 'prompt' => "2/15 — What *course or program* do you want to study?"],
        ['key' => 'country_interest', 'prompt' => "3/15 — Which *country* are you interested in?"],
        ['key' => 'open_other_countries', 'prompt' => "4/15 — Are you open to other countries (India 🇮🇳, Cyprus 🇨🇾, Malta 🇲🇹 under \$15,000/year)?"],
        ['key' => 'budget_tuition', 'prompt' => "5/15 — What is your estimated *budget for tuition per year*?"],
        ['key' => 'funds_application_visa', 'prompt' => "6/15 — Do you have funds for *application and visa fees*? Reply *Yes* or *No*."],
        ['key' => 'sponsor', 'prompt' => "7/15 — Who will *sponsor* your studies? Reply: *Self*, *Parent*, or *Sponsor*."],
        ['key' => 'afford_deposit', 'prompt' => "8/15 — Can you afford *initial deposit and accommodation*? Reply *Yes* or *No*."],
        ['key' => 'has_valid_passport', 'prompt' => "9/15 — Do you have a *valid passport*? Reply *Yes* or *No*."],
        ['key' => 'academic_docs_ready', 'prompt' => "10/15 — Do you have *academic documents* ready (transcripts & certificates)? Reply *Yes*, *No*, or *Partially*."],
        ['key' => 'english_level', 'prompt' => "11/15 — What is your *level of English*? Reply: *Basic*, *Good*, or *Test done*."],
        ['key' => 'english_test_taken', 'prompt' => "12/15 — Have you taken *IELTS / TOEFL / Duolingo*? (Type scores or *No*)"],
        ['key' => 'visa_denied', 'prompt' => "13/15 — Have you ever been *denied a visa*? Reply *Yes* or *No*."],
        ['key' => 'planned_intake', 'prompt' => "14/15 — When do you plan to *start* (intake)? e.g. Fall 2026"],
        ['key' => 'ready_to_apply', 'prompt' => "15/15 — Are you ready to start the *application process now*? Reply *Yes* or *No*."],
    ];
}

/** @return array<int, array{key:string,label:string}> */
function xander_prescreening_whatsapp_document_steps(): array
{
    $docs = [];
    foreach (xander_prescreening_document_labels() as $key => $label) {
        $docs[] = ['key' => $key, 'label' => $label];
    }

    return $docs;
}

function xander_prescreening_staff_whatsapp_numbers(): array
{
    xander_load_env_file();
    $raw = xander_env_get('PRESCREENING_STAFF_WHATSAPP');
    if ($raw === '') {
        $raw = '12704387305,254711807646';
    }
    $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($parts as $p) {
        $digits = preg_replace('/\D+/', '', $p);
        if ($digits !== null && strlen($digits) >= 10) {
            $out[] = $digits;
        }
    }

    return array_values(array_unique($out));
}

function xander_whatsapp_api_messages_url(): ?array
{
    $token = xander_env_get('WHATSAPP_ACCESS_TOKEN');
    $phoneId = xander_env_get('WHATSAPP_PHONE_NUMBER_ID');
    if ($token === '' || $phoneId === '') {
        return null;
    }
    $version = xander_env_get('META_GRAPH_VERSION');
    if ($version === '') {
        $version = 'v19.0';
    }

    return [
        'token' => $token,
        'phone_id' => $phoneId,
        'version' => $version,
        'url' => 'https://graph.facebook.com/' . rawurlencode($version) . '/' . rawurlencode($phoneId) . '/messages',
    ];
}

function xander_whatsapp_send_plain_text(string $toPhone, string $body): bool
{
    $api = xander_whatsapp_api_messages_url();
    if ($api === null) {
        return false;
    }
    $payload = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $toPhone,
        'type' => 'text',
        'text' => ['preview_url' => false, 'body' => xander_notify_text_clip($body, 4096)],
    ];
    $res = xander_whatsapp_graph_post($api['url'], $api['token'], $payload);

    return $res['http'] >= 200 && $res['http'] < 300 && xander_whatsapp_response_has_message_id($res['json']);
}

function xander_prescreening_is_trigger(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') {
        return false;
    }
    foreach (XANDER_PRESCREENING_TRIGGERS as $kw) {
        if ($t === $kw || str_contains($t, $kw)) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<string, mixed>
 */
function xander_prescreening_session_decode(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $d = json_decode($json, true);

    return is_array($d) ? $d : [];
}

function xander_prescreening_wa_dedup_seen(mysqli $conn, string $messageId): bool
{
    $messageId = trim($messageId);
    if ($messageId === '') {
        return false;
    }
    $stmt = $conn->prepare('INSERT IGNORE INTO whatsapp_inbound_dedup (message_id) VALUES (?)');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $messageId);
    $stmt->execute();
    $inserted = $stmt->affected_rows > 0;
    $stmt->close();

    return !$inserted;
}

/**
 * @return array<string, mixed>|null
 */
function xander_prescreening_load_session(mysqli $conn, string $waPhone): ?array
{
    $stmt = $conn->prepare('SELECT * FROM whatsapp_prescreening_sessions WHERE wa_phone = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $waPhone);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function xander_prescreening_save_session(mysqli $conn, string $waPhone, string $step, array $answers, int $docIndex): void
{
    $json = json_encode($answers, JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare('
        INSERT INTO whatsapp_prescreening_sessions (wa_phone, current_step, answers_json, doc_index)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE current_step = VALUES(current_step),
            answers_json = VALUES(answers_json),
            doc_index = VALUES(doc_index),
            updated_at = NOW()
    ');
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('sssi', $waPhone, $step, $json, $docIndex);
    $stmt->execute();
    $stmt->close();
}

function xander_prescreening_reset_session(mysqli $conn, string $waPhone): void
{
    $stmt = $conn->prepare('DELETE FROM whatsapp_prescreening_sessions WHERE wa_phone = ?');
    if ($stmt) {
        $stmt->bind_param('s', $waPhone);
        $stmt->execute();
        $stmt->close();
    }
}

function xander_prescreening_normalize_wa_phone(string $raw): ?string
{
    xander_load_env_file();
    $cc = xander_env_get('WHATSAPP_DEFAULT_COUNTRY_CODE');

    return xander_format_phone_for_whatsapp_e164($raw, $cc !== '' ? $cc : null);
}

/**
 * Admin sidebar: send invite template first, then student replies on WhatsApp.
 *
 * @return array{sent:bool,error:string,to:string}
 */
function xander_prescreening_admin_send_invite(mysqli $conn, string $phoneRaw, string $studentName = ''): array
{
    $out = ['sent' => false, 'error' => '', 'to' => ''];
    $to = xander_prescreening_normalize_wa_phone($phoneRaw);
    if ($to === null) {
        $out['error'] = 'Invalid WhatsApp number — include country code.';

        return $out;
    }
    $out['to'] = $to;

    $api = xander_whatsapp_api_messages_url();
    if ($api === null) {
        $out['error'] = 'WhatsApp API is not configured in .env.';

        return $out;
    }

    $name = trim($studentName) !== '' ? trim($studentName) : 'Student';
    $fallback = "Hello {$name}, Xander Global Scholars invites you to complete Quick Pre-Screening on WhatsApp. Reply *START* to begin (15 questions and documents). Type CANCEL to stop.";

    $res = xander_whatsapp_send_template_or_session(
        $to,
        $api['url'],
        $api['token'],
        xander_prescreening_invite_template_name(),
        xander_prescreening_invite_template_lang(),
        1,
        [$name],
        $fallback
    );

    if (!$res['sent']) {
        $out['error'] = $res['error'] !== '' ? $res['error'] : 'Could not send WhatsApp invite template.';

        return $out;
    }

    $answers = ['student_name' => $name];
    xander_prescreening_save_session($conn, $to, 'invited', $answers, 0);

    $out['sent'] = true;

    return $out;
}

function xander_prescreening_begin_questions(mysqli $conn, string $waPhone, array $answers): void
{
    $questions = xander_prescreening_whatsapp_question_steps();
    $startIdx = 0;
    if (trim((string) ($answers['student_name'] ?? '')) !== '') {
        $startIdx = 1;
    }
    xander_prescreening_save_session($conn, $waPhone, 'q:' . $startIdx, $answers, 0);
    $intro = "*Xander Global Scholars — Quick Pre-screening*\n\n"
        . "Please answer each question in order.\n"
        . "• Type *CANCEL* anytime to stop.\n"
        . "• For documents, send a photo/PDF or type *skip*.\n\n"
        . $questions[$startIdx]['prompt'];
    xander_whatsapp_send_plain_text($waPhone, $intro);
}

function xander_prescreening_start_flow(mysqli $conn, string $waPhone): void
{
    xander_prescreening_begin_questions($conn, $waPhone, []);
}

/**
 * @param array<string, mixed> $message Meta message object
 */
function xander_prescreening_extract_inbound_text(array $message): string
{
    $type = (string) ($message['type'] ?? '');
    if ($type === 'text') {
        return trim((string) ($message['text']['body'] ?? ''));
    }
    // Template quick-reply buttons (Custom → START / CANCEL) arrive as type "button"
    if ($type === 'button') {
        return trim((string) ($message['button']['text'] ?? $message['button']['payload'] ?? ''));
    }
    if ($type === 'interactive') {
        $btn = $message['interactive']['button_reply']['title'] ?? $message['interactive']['button_reply']['id'] ?? '';
        $list = $message['interactive']['list_reply']['title'] ?? $message['interactive']['list_reply']['id'] ?? '';

        return trim((string) ($btn !== '' ? $btn : $list));
    }

    return '';
}

/** Normalized tap/text for START and CANCEL (template buttons or typed). */
function xander_prescreening_normalize_action(string $text): string
{
    $t = strtolower(trim($text));

    return match ($t) {
        'start', 'yes', 'begin', 'ok', 'okay' => 'start',
        'cancel', 'stop', 'quit', 'end' => 'cancel',
        default => $t,
    };
}

/**
 * @return array{media_id:string,mime:string,filename:string}|null
 */
function xander_prescreening_extract_inbound_media(array $message): ?array
{
    $type = (string) ($message['type'] ?? '');
    if ($type === 'document') {
        return [
            'media_id' => (string) ($message['document']['id'] ?? ''),
            'mime' => (string) ($message['document']['mime_type'] ?? 'application/pdf'),
            'filename' => (string) ($message['document']['filename'] ?? 'document.pdf'),
        ];
    }
    if ($type === 'image') {
        return [
            'media_id' => (string) ($message['image']['id'] ?? ''),
            'mime' => (string) ($message['image']['mime_type'] ?? 'image/jpeg'),
            'filename' => 'image.jpg',
        ];
    }

    return null;
}

function xander_whatsapp_download_media_to_file(string $mediaId, string $destAbsPath): bool
{
    $token = xander_env_get('WHATSAPP_ACCESS_TOKEN');
    if ($token === '' || $mediaId === '' || !function_exists('curl_init')) {
        return false;
    }
    $version = xander_env_get('META_GRAPH_VERSION') ?: 'v19.0';
    $metaUrl = 'https://graph.facebook.com/' . rawurlencode($version) . '/' . rawurlencode($mediaId);

    $ch = curl_init($metaUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $metaBody = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        return false;
    }
    $meta = json_decode((string) $metaBody, true);
    $downloadUrl = is_array($meta) ? (string) ($meta['url'] ?? '') : '';
    if ($downloadUrl === '') {
        return false;
    }

    $ch2 = curl_init($downloadUrl);
    curl_setopt_array($ch2, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $binary = curl_exec($ch2);
    $code2 = (int) curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    if ($code2 < 200 || $code2 >= 300 || $binary === false) {
        return false;
    }

    $dir = dirname($destAbsPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return file_put_contents($destAbsPath, $binary) !== false;
}

function xander_prescreening_validate_answer(string $key, string $answer): ?string
{
    $a = trim($answer);
    if ($key === 'student_email' && strtolower($a) === 'skip') {
        return '';
    }
    if ($key === 'student_email' && $a !== '' && !filter_var($a, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email or type *skip*.';
    }
    $yesNo = ['funds_application_visa', 'afford_deposit', 'has_valid_passport', 'visa_denied', 'ready_to_apply'];
    if (in_array($key, $yesNo, true)) {
        $n = strtolower($a);
        if (!in_array($n, ['yes', 'no', 'y', 'n'], true)) {
            return 'Please reply *Yes* or *No*.';
        }

        return in_array($n, ['y', 'yes'], true) ? 'Yes' : 'No';
    }
    if ($key === 'sponsor') {
        $n = ucfirst(strtolower($a));
        if (!in_array($n, ['Self', 'Parent', 'Sponsor'], true)) {
            return 'Please reply *Self*, *Parent*, or *Sponsor*.';
        }

        return $n;
    }
    if ($key === 'academic_docs_ready') {
        $n = ucfirst(strtolower($a));
        if (!in_array($n, ['Yes', 'No', 'Partially'], true)) {
            return 'Please reply *Yes*, *No*, or *Partially*.';
        }

        return $n;
    }
    if ($key === 'english_level') {
        $map = ['basic' => 'Basic', 'good' => 'Good', 'test done' => 'Test done', 'test' => 'Test done'];
        $n = strtolower($a);
        if (isset($map[$n])) {
            return $map[$n];
        }
        if (in_array(ucfirst($n), ['Basic', 'Good'], true)) {
            return ucfirst($n);
        }

        return 'Please reply *Basic*, *Good*, or *Test done*.';
    }
    if ($a === '') {
        return 'Please send an answer (cannot be empty).';
    }

    return $a;
}

/**
 * @param array<string, mixed> $answers
 * @return array<string, mixed>
 */
function xander_prescreening_row_from_answers(array $answers, string $waPhone): array
{
    $row = $answers;
    $row['whatsapp_number'] = '+' . $waPhone;
    if (!isset($row['student_name'])) {
        $row['student_name'] = 'WhatsApp Student';
    }

    return $row;
}

function xander_prescreening_notify_staff_whatsapp(array $row, string $reference): array
{
    xander_load_env_file();
    $numbers = xander_prescreening_staff_whatsapp_numbers();
    $api = xander_whatsapp_api_messages_url();
    if ($api === null || $numbers === []) {
        return ['sent' => 0, 'error' => 'Staff WhatsApp numbers or API not configured.'];
    }

    $body = "*New pre-screening (WhatsApp)*\n"
        . "Ref: *" . xander_whatsapp_sanitize_user_text($reference) . "*\n"
        . "Student: *" . xander_whatsapp_sanitize_user_text((string) ($row['student_name'] ?? '')) . "*\n"
        . "WhatsApp: " . xander_whatsapp_sanitize_user_text((string) ($row['whatsapp_number'] ?? '')) . "\n"
        . "Email: " . xander_whatsapp_sanitize_user_text((string) ($row['student_email'] ?? '—')) . "\n\n"
        . "*Answers:*\n";
    foreach (xander_prescreening_build_summary_lines($row) as $line) {
        $body .= xander_whatsapp_sanitize_user_text($line) . "\n";
    }

    $docPaths = [];
    foreach (array_keys(xander_prescreening_document_labels()) as $key) {
        $docPaths[$key] = (string) ($row[$key] ?? '');
    }
    $attachments = xander_prescreening_collect_attachments($docPaths);

    $sent = 0;
    foreach ($numbers as $staffTo) {
        $textOk = xander_whatsapp_send_plain_text($staffTo, $body);
        if ($textOk) {
            $sent++;
        }
        foreach ($attachments as $att) {
            $mediaId = xander_whatsapp_upload_media_file($api['phone_id'], $api['token'], $api['version'], $att['path']);
            if ($mediaId !== null) {
                xander_whatsapp_send_document_message(
                    $api['url'],
                    $api['token'],
                    $staffTo,
                    $mediaId,
                    'Pre-screening: ' . basename($att['name']),
                    basename($att['path'])
                );
            }
            usleep(350000);
        }
    }

    return ['sent' => $sent, 'targets' => count($numbers), 'error' => ''];
}

/**
 * @param array<string, mixed> $row
 */
function xander_prescreening_finalize_submission(mysqli $conn, array $row, string $waPhone): string
{
    $userId = 'wa-' . $waPhone . '-' . time();
    $reference = 'PS-' . strtoupper(substr(md5($userId), 0, 8));
    $submittedAt = date('Y-m-d H:i:s');

    $sql = "INSERT INTO prescreening_submissions (
        user_id, source, student_name, student_email, whatsapp_number,
        education_level, course_program, country_interest, open_other_countries,
        budget_tuition, funds_application_visa, sponsor, afford_deposit,
        has_valid_passport, academic_docs_ready, english_level, english_test_taken,
        visa_denied, planned_intake, ready_to_apply,
        doc_valid_passport, doc_degree_transcripts, doc_high_school, doc_cv_resume,
        doc_recommendation, doc_personal_statement, doc_english_certificate,
        doc_birth_certificate, doc_payment_proof, submitted_at
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('DB prepare failed');
    }

    $bind = [
        $userId, 'whatsapp',
        (string) ($row['student_name'] ?? ''),
        (string) ($row['student_email'] ?? ''),
        (string) ($row['whatsapp_number'] ?? ''),
        (string) ($row['education_level'] ?? ''),
        (string) ($row['course_program'] ?? ''),
        (string) ($row['country_interest'] ?? ''),
        (string) ($row['open_other_countries'] ?? ''),
        (string) ($row['budget_tuition'] ?? ''),
        (string) ($row['funds_application_visa'] ?? ''),
        (string) ($row['sponsor'] ?? ''),
        (string) ($row['afford_deposit'] ?? ''),
        (string) ($row['has_valid_passport'] ?? ''),
        (string) ($row['academic_docs_ready'] ?? ''),
        (string) ($row['english_level'] ?? ''),
        (string) ($row['english_test_taken'] ?? ''),
        (string) ($row['visa_denied'] ?? ''),
        (string) ($row['planned_intake'] ?? ''),
        (string) ($row['ready_to_apply'] ?? ''),
        (string) ($row['doc_valid_passport'] ?? ''),
        (string) ($row['doc_degree_transcripts'] ?? ''),
        (string) ($row['doc_high_school'] ?? ''),
        (string) ($row['doc_cv_resume'] ?? ''),
        (string) ($row['doc_recommendation'] ?? ''),
        (string) ($row['doc_personal_statement'] ?? ''),
        (string) ($row['doc_english_certificate'] ?? ''),
        (string) ($row['doc_birth_certificate'] ?? ''),
        (string) ($row['doc_payment_proof'] ?? ''),
        $submittedAt,
    ];
    $types = str_repeat('s', 30);
    $stmt->bind_param($types, ...$bind);
    $stmt->execute();
    $stmt->close();

    xander_send_prescreening_notifications($row, $reference, true);
    xander_prescreening_notify_staff_whatsapp($row, $reference);

    return $reference;
}

/**
 * Handle one inbound WhatsApp message from a student.
 *
 * @return bool true = consumed (do not run chatbot)
 */
function xander_prescreening_handle_inbound(mysqli $conn, string $waPhone, array $message): bool
{
    $text = xander_prescreening_extract_inbound_text($message);
    $media = xander_prescreening_extract_inbound_media($message);
    $action = xander_prescreening_normalize_action($text);

    if ($action === 'cancel') {
        xander_prescreening_reset_session($conn, $waPhone);
        xander_whatsapp_send_plain_text($waPhone, 'Pre-screening cancelled. Your school will send a new invite when ready.');
        return true;
    }

    $session = xander_prescreening_load_session($conn, $waPhone);
    $step = $session ? (string) ($session['current_step'] ?? 'idle') : 'idle';
    $answers = xander_prescreening_session_decode($session['answers_json'] ?? null);
    $docIndex = $session ? (int) ($session['doc_index'] ?? 0) : 0;

    if ($step === 'invited') {
        if ($text === '' && $media === null) {
            xander_whatsapp_send_plain_text($waPhone, 'Tap *START* or type START to begin. Tap *CANCEL* to stop.');
            return true;
        }
        if ($action !== 'start' && $action !== 'cancel') {
            xander_whatsapp_send_plain_text(
                $waPhone,
                'Tap the *START* button above to begin pre-screening, or type *CANCEL* to stop.'
            );
            return true;
        }
        xander_prescreening_begin_questions($conn, $waPhone, $answers);
        return true;
    }

    if ($step === 'idle') {
        if (!xander_prescreening_is_trigger($text)) {
            return false;
        }
        xander_prescreening_start_flow($conn, $waPhone);
        return true;
    }

    $questions = xander_prescreening_whatsapp_question_steps();
    $docs = xander_prescreening_whatsapp_document_steps();

    if (str_starts_with($step, 'q:')) {
        $qi = (int) substr($step, 2);
        if ($qi < 0 || $qi >= count($questions)) {
            xander_prescreening_reset_session($conn, $waPhone);
            return true;
        }
        $q = $questions[$qi];
        if ($text === '' && $media === null) {
            xander_whatsapp_send_plain_text($waPhone, "Please send a text answer.\n\n" . $q['prompt']);
            return true;
        }
        $validated = xander_prescreening_validate_answer($q['key'], $text);
        if (is_string($validated) && str_starts_with($validated, 'Please')) {
            xander_whatsapp_send_plain_text($waPhone, $validated . "\n\n" . $q['prompt']);
            return true;
        }
        $answers[$q['key']] = $validated;
        $next = $qi + 1;
        if ($next < count($questions)) {
            xander_prescreening_save_session($conn, $waPhone, 'q:' . $next, $answers, 0);
            xander_whatsapp_send_plain_text($waPhone, $questions[$next]['prompt']);
            return true;
        }
        $docIndex = 0;
        xander_prescreening_save_session($conn, $waPhone, 'doc:0', $answers, 0);
        $label = $docs[0]['label'];
        xander_whatsapp_send_plain_text(
            $waPhone,
            "*Documents (1/" . count($docs) . ")*\nPlease send: *{$label}*\n(Photo/PDF or type *skip*)"
        );
        return true;
    }

    if (str_starts_with($step, 'doc:')) {
        $di = (int) substr($step, 4);
        if ($di < 0 || $di >= count($docs)) {
            return true;
        }
        $doc = $docs[$di];
        if ($action === 'skip') {
            $answers[$doc['key']] = '';
        } elseif ($media !== null && ($media['media_id'] ?? '') !== '') {
            $ext = 'pdf';
            if (str_contains((string) $media['mime'], 'image')) {
                $ext = 'jpg';
            }
            $fname = 'wa-' . $waPhone . '_' . $doc['key'] . '_' . time() . '.' . $ext;
            $rel = 'uploads/prescreening/' . $fname;
            $abs = dirname(__DIR__) . '/' . $rel;
            if (xander_whatsapp_download_media_to_file($media['media_id'], $abs)) {
                $answers[$doc['key']] = $rel;
            } else {
                xander_whatsapp_send_plain_text($waPhone, 'Could not save file. Please try again or type *skip*.');
                return true;
            }
        } else {
            xander_whatsapp_send_plain_text($waPhone, 'Please send the document as a file/photo, or type *skip*.');
            return true;
        }

        $nextDoc = $di + 1;
        if ($nextDoc < count($docs)) {
            xander_prescreening_save_session($conn, $waPhone, 'doc:' . $nextDoc, $answers, $nextDoc);
            $label = $docs[$nextDoc]['label'];
            xander_whatsapp_send_plain_text(
                $waPhone,
                "*Documents (" . ($nextDoc + 1) . '/' . count($docs) . ")*\nPlease send: *{$label}*\n(Photo/PDF or type *skip*)"
            );
            return true;
        }

        xander_prescreening_reset_session($conn, $waPhone);
        $row = xander_prescreening_row_from_answers($answers, $waPhone);
        try {
            $ref = xander_prescreening_finalize_submission($conn, $row, $waPhone);
            xander_whatsapp_send_plain_text(
                $waPhone,
                "✅ *Pre-screening complete!*\n\nThank you, *" . ($row['student_name'] ?? 'Student') . "*.\n"
                . "Reference: *{$ref}*\n\nOur team has received your answers and documents and will contact you soon.\n\n— Xander Global Scholars"
            );
        } catch (Throwable $e) {
            error_log('[prescreening_whatsapp_flow] finalize: ' . $e->getMessage());
            xander_whatsapp_send_plain_text($waPhone, 'Something went wrong saving your form. Please try again or contact admissions.');
        }
        return true;
    }

    return true;
}

function xander_whatsapp_verify_webhook_signature(string $payload, string $signatureHeader): bool
{
    $secret = xander_env_get('WHATSAPP_APP_SECRET');
    if ($secret === '') {
        return true;
    }
    if ($signatureHeader === '' || !str_starts_with($signatureHeader, 'sha256=')) {
        return false;
    }
    $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

    return hash_equals($expected, $signatureHeader);
}
