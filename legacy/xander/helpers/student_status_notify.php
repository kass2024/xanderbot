<?php
/**
 * Applicant status notifications only (students-manage → update-flag.php).
 * Does not affect admin password reset (helpers/admin_password_reset.php).
 *
 * Email includes when present: status, study level (intended_study_level), program lines,
 * universities + regions from application_study_choices, destination.
 *
 * {STATUS LABEL} for each saved flag (DB column set to 1):
 *   incomplete_app → Incomplete App
 *   submitted → Submitted
 *   app_paid → App Paid
 *   admit → Admit
 *   i20_sent → I-20 Sent
 *   sevis_paid → Sevis Paid
 *   visa_scheduled → Visa Scheduled
 *   visa_approved → Visa Approved
 *   enrolled → Enrolled
 *   addn_doc → Additional Document
 *   deny → Rejected
 *   app_start → App Start
 *
 * --- WhatsApp Cloud API ---
 * Template (XANDER_WHATSAPP_STATUS_TEMPLATE_NAME): body vars {{1}} name, {{2}} status; optional {{3}} detail
 * block (set XANDER_WHATSAPP_STATUS_TEMPLATE_PARAMS=3 when your Meta template includes a third placeholder).
 * Session text (24h window) uses the same application details as email (study level, programs, universities, regions, destination).
 * API credentials: WHATSAPP_* in .env (loaded via env_load.php).
 * Optional: WHATSAPP_DEFAULT_COUNTRY_CODE — digits only, no + (e.g. 234, 1, 44). Used when the stored
 * number is national (leading 0) or 10 digits without country code.
 *
 * Notifications are sent only when staff chooses Email and/or WhatsApp in the UI (not automatic).
 * Mail transport: helpers/mail_smtp.php only.
 */
require_once __DIR__ . '/mail_smtp.php';
require_once __DIR__ . '/env_load.php';

/** Meta-approved WhatsApp template name; empty = WhatsApp option does nothing until set. */
const XANDER_WHATSAPP_STATUS_TEMPLATE_NAME = '';

const XANDER_WHATSAPP_STATUS_TEMPLATE_LANG = 'en_US';

/** Body variables: {{1}} name, {{2}} status; optional {{3}} details — set to 3 when Meta template includes a third placeholder. */
const XANDER_WHATSAPP_STATUS_TEMPLATE_PARAMS = 2;

/**
 * Human-readable labels (keep in sync with students-manage.php $statusOptions).
 */
function xander_student_status_flag_labels(): array
{
    return [
        'incomplete_app' => 'Incomplete App',
        'submitted' => 'Submitted',
        'app_paid' => 'App Paid',
        'admit' => 'Admit',
        'i20_sent' => 'I-20 Sent',
        'sevis_paid' => 'Sevis Paid',
        'visa_scheduled' => 'Visa Scheduled',
        'visa_approved' => 'Visa Approved',
        'enrolled' => 'Enrolled',
        'addn_doc' => 'Additional Document',
        'deny' => 'Rejected',
        'app_start' => 'App Start',
    ];
}

function xander_student_status_label(string $flag): string
{
    $map = xander_student_status_flag_labels();
    return $map[$flag] ?? $flag;
}

function xander_notify_text_clip(string $s, int $max): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max);
    }
    return (strlen($s) <= $max) ? $s : substr($s, 0, $max);
}

/**
 * Plain detail lines (same information as email AltBody): study level, programs, universities, regions, destination.
 *
 * @return array<int, string>
 */
function xander_status_notify_detail_lines(array $ctx): array
{
    $lines = [];

    $pl = trim((string) ($ctx['program_level'] ?? ''));
    if ($pl !== '') {
        $lines[] = 'Study level: ' . $pl;
    }

    $programs = $ctx['programs'] ?? [];
    if (is_array($programs)) {
        foreach ($programs as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
    }

    $uni = trim((string) ($ctx['universities'] ?? ''));
    if ($uni !== '') {
        $lines[] = 'Universities: ' . $uni;
    }

    $reg = trim((string) ($ctx['regions'] ?? ''));
    if ($reg !== '') {
        $lines[] = 'Study regions: ' . $reg;
    }

    $dest = trim((string) ($ctx['destination'] ?? ''));
    if ($dest !== '') {
        $lines[] = 'Destination: ' . $dest;
    }

    return $lines;
}

/**
 * Strip WhatsApp formatting characters from user-supplied text so *bold* in our template is not broken.
 */
function xander_whatsapp_sanitize_user_text(string $s): string
{
    return str_replace(['*', '_', '~', '`'], ['·', '·', "'", "'"], $s);
}

/**
 * One block for template variable {{3}} (newlines inside one parameter).
 */
function xander_whatsapp_detail_summary_for_template(array $ctx): string
{
    $lines = xander_status_notify_detail_lines($ctx);
    if ($lines === []) {
        return '—';
    }
    $safe = [];
    foreach ($lines as $line) {
        $safe[] = xander_whatsapp_sanitize_user_text($line);
    }

    return implode("\n", $safe);
}

/**
 * Rich session message (24h window): same fields as email, WhatsApp *bold* section titles.
 */
function xander_whatsapp_session_text_body(string $studentName, string $statusLabel, array $ctx, string $rejectionReason = ''): string
{
    $name = xander_whatsapp_sanitize_user_text($studentName !== '' ? $studentName : 'Applicant');
    $status = xander_whatsapp_sanitize_user_text($statusLabel);

    $parts = [
        '*Xander Global Scholars*',
        '*Application status update*',
        '',
        'Hello ' . $name . ',',
        '',
        'Your application status is now:',
        '*' . $status . '*',
    ];

    $rr = trim($rejectionReason);
    if ($rr !== '') {
        $parts[] = '';
        $parts[] = '*Message from our team*';
        $parts[] = xander_whatsapp_sanitize_user_text($rr);
    }

    $detailLines = xander_status_notify_detail_lines($ctx);
    if ($detailLines !== []) {
        $parts[] = '';
        $parts[] = '*Application details*';
        foreach ($detailLines as $line) {
            $parts[] = xander_whatsapp_sanitize_user_text($line);
        }
    }

    $parts[] = '';
    $parts[] = 'Questions? Reply on WhatsApp.';
    $parts[] = '';
    $parts[] = '— Xander Global Scholars';

    return xander_notify_text_clip(implode("\n", $parts), 4096);
}

/**
 * Universities / regions from application_study_choices (student_applications.id).
 */
function xander_fetch_study_choices_bundle(mysqli $conn, int $applicationId): array
{
    $out = ['universities' => '', 'regions' => ''];
    $applicationId = (int) $applicationId;
    if ($applicationId <= 0) {
        return $out;
    }
    $sql = "SELECT 
        GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ' · ') AS uni,
        GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ' · ') AS reg
    FROM application_study_choices ascx
    LEFT JOIN universities u ON u.id = ascx.university_id
    LEFT JOIN regions r ON r.id = ascx.region_id
    WHERE ascx.application_id = {$applicationId}";
    $res = @$conn->query($sql);
    if ($res && ($row = $res->fetch_assoc())) {
        $out['universities'] = trim((string) ($row['uni'] ?? ''));
        $out['regions'] = trim((string) ($row['reg'] ?? ''));
        $res->free();
    }
    return $out;
}

/**
 * @return array<string, mixed>|null
 */
function xander_fetch_applicant_for_notify(mysqli $conn, string $table, int $id): ?array
{
    $allowed = ['student_applications', 'malta_applications', 'turkey_applications'];
    if (!in_array($table, $allowed, true)) {
        return null;
    }
    $id = (int) $id;
    if ($id <= 0) {
        return null;
    }
    // Int + whitelisted table only (avoids mysqli_stmt::get_result() when mysqlnd is missing — common on cPanel).
    $sql = "SELECT * FROM `{$table}` WHERE id = {$id} LIMIT 1";
    $res = $conn->query($sql);
    if (!$res) {
        error_log('[student_status_notify] SELECT failed: ' . $conn->error);
        return null;
    }
    $row = $res->fetch_assoc();
    $res->free();
    if (!$row) {
        return null;
    }

    $email = trim((string) ($row['email'] ?? ''));
    $name = '';
    $program = '';
    $destination = trim((string) ($row['destination'] ?? ''));
    $emailContext = [
        'program_level' => '',
        'programs' => [],
        'universities' => '',
        'regions' => '',
        'destination' => '',
    ];

    if ($table === 'student_applications') {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $program = trim((string) ($row['masters_program'] ?? $row['bachelor_program'] ?? $row['phd_program'] ?? ''));
        $ac = trim((string) ($row['area_code'] ?? ''));
        $pn = trim((string) ($row['phone_number'] ?? ''));
        $phone = trim($ac . ' ' . $pn);

        $emailContext['program_level'] = trim((string) ($row['intended_study_level'] ?? ''));
        $emailContext['destination'] = $destination;

        $slots = [
            'Bachelor' => trim((string) ($row['bachelor_program'] ?? '')),
            "Master's" => trim((string) ($row['masters_program'] ?? '')),
            'PhD' => trim((string) ($row['phd_program'] ?? '')),
            'Advanced diploma' => trim((string) ($row['advanced_diploma_program'] ?? '')),
            'College diploma' => trim((string) ($row['college_diploma_program'] ?? '')),
            'College certificate' => trim((string) ($row['college_certificate_program'] ?? '')),
            'Graduate certificate' => trim((string) ($row['graduate_certificate_program'] ?? '')),
        ];
        foreach ($slots as $label => $val) {
            if ($val !== '') {
                $emailContext['programs'][] = $label . ': ' . $val;
            }
        }
        $sc = xander_fetch_study_choices_bundle($conn, $id);
        $emailContext['universities'] = $sc['universities'];
        $emailContext['regions'] = $sc['regions'];
    } elseif ($table === 'malta_applications') {
        $name = trim(($row['name'] ?? '') . ' ' . ($row['surname'] ?? ''));
        $program = trim((string) ($row['degree_program'] ?? ''));
        $destination = $destination !== '' ? $destination : 'Malta';
        $phone = trim((string) ($row['contact_number'] ?? ''));
        $dp = trim((string) ($row['degree_program'] ?? ''));
        if ($dp !== '') {
            $emailContext['programs'][] = $dp;
        }
        $emailContext['destination'] = $destination;
    } else {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $phone = trim((string) ($row['mobile'] ?? ''));
        $destination = $destination !== '' ? $destination : 'Turkey';
        $emailContext['destination'] = $destination;
    }

    return [
        'email' => $email,
        'phone' => $phone,
        'name' => $name,
        'program' => $program,
        'destination' => $destination,
        'email_context' => $emailContext,
    ];
}

/**
 * Digits-only E.164 for WhatsApp Cloud API `to` (no + prefix).
 * Uses $defaultCountryDigits when the stored value is national (leading 0) or 10 digits without CC.
 *
 * @param string|null $defaultCountryDigits e.g. "234", "1", "44" from WHATSAPP_DEFAULT_COUNTRY_CODE
 */
function xander_format_phone_for_whatsapp_e164(string $raw, ?string $defaultCountryDigits): ?string
{
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === null || $digits === '') {
        return null;
    }

    $cc = $defaultCountryDigits !== null && $defaultCountryDigits !== ''
        ? preg_replace('/\D+/', '', $defaultCountryDigits)
        : '';
    $cc = $cc === '' ? null : $cc;

    $len = strlen($digits);

    // National numbers with leading 0 (e.g. 0803… NG, 07… UK) → CC + national significant digits
    if ($len >= 10 && $len <= 12 && $digits[0] === '0' && $cc !== null) {
        $rest = substr($digits, 1);
        if (strlen($rest) >= 9 && strlen($rest) <= 14) {
            return $cc . $rest;
        }
    }

    // Already includes country code (typical 11–15 digits, no leading 0)
    if ($len >= 11 && $len <= 15 && $digits[0] !== '0') {
        return $digits;
    }

    // 10-digit national without country: apply default CC (NANP or local convention)
    if ($len === 10 && $digits[0] !== '0') {
        if ($cc === '1') {
            return '1' . $digits;
        }
        if ($cc !== null && $cc !== '1') {
            return $cc . $digits;
        }

        return null;
    }

    // 8–9 digits: too short for reliable international send
    if ($len < 10) {
        return null;
    }

    // Fallback: 10–15 digit strings that did not match above
    if ($len >= 10 && $len <= 15 && $digits[0] !== '0') {
        return $digits;
    }

    return null;
}

function xander_send_student_status_email(string $toEmail, string $studentName, string $statusLabel, array $ctx, string $rejectionReason = ''): bool
{
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    try {
        $mail = xander_create_phpmailer();
        $mail->addAddress($toEmail, $studentName ?: $toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Application status update — Xander Global Scholars';
        $safeName = htmlspecialchars($studentName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeStatus = htmlspecialchars($statusLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $rows = '';

        $pl = trim((string) ($ctx['program_level'] ?? ''));
        if ($pl !== '') {
            $s = htmlspecialchars($pl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $rows .= '<tr><td style="padding:10px 16px;border-bottom:1px solid #e2e8f0;color:#64748b;font-size:12px;width:38%;vertical-align:top;">Study level</td>'
                . '<td style="padding:10px 16px;border-bottom:1px solid #e2e8f0;color:#0f172a;font-size:14px;">' . $s . '</td></tr>';
        }

        $programs = $ctx['programs'] ?? [];
        if (is_array($programs) && count($programs) > 0) {
            $list = '<ul style="margin:0;padding-left:18px;color:#0f172a;font-size:14px;line-height:1.55;">';
            foreach ($programs as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                $list .= '<li>' . htmlspecialchars($line, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</li>';
            }
            $list .= '</ul>';
            $rows .= '<tr><td style="padding:10px 16px;border-bottom:1px solid #e2e8f0;color:#64748b;font-size:12px;vertical-align:top;">Programs</td>'
                . '<td style="padding:10px 16px;border-bottom:1px solid #e2e8f0;">' . $list . '</td></tr>';
        }

        $uni = trim((string) ($ctx['universities'] ?? ''));
        if ($uni !== '') {
            $s = htmlspecialchars($uni, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $rows .= '<tr><td style="padding:10px 16px;border-bottom:1px solid #e2e8f0;color:#64748b;font-size:12px;vertical-align:top;">Universities</td>'
                . '<td style="padding:10px 16px;border-bottom:1px solid #e2e8f0;color:#0f172a;font-size:14px;">' . $s . '</td></tr>';
        }

        $reg = trim((string) ($ctx['regions'] ?? ''));
        if ($reg !== '') {
            $s = htmlspecialchars($reg, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $rows .= '<tr><td style="padding:10px 16px;border-bottom:1px solid #e2e8f0;color:#64748b;font-size:12px;vertical-align:top;">Study regions</td>'
                . '<td style="padding:10px 16px;border-bottom:1px solid #e2e8f0;color:#0f172a;font-size:14px;">' . $s . '</td></tr>';
        }

        $dest = trim((string) ($ctx['destination'] ?? ''));
        if ($dest !== '') {
            $s = htmlspecialchars($dest, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $rows .= '<tr><td style="padding:10px 16px;color:#64748b;font-size:12px;vertical-align:top;">Destination</td>'
                . '<td style="padding:10px 16px;color:#0f172a;font-size:14px;">' . $s . '</td></tr>';
        }

        $plainLines = xander_status_notify_detail_lines($ctx);

        $detailBlock = '';
        if ($rows !== '') {
            $detailBlock = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin:20px 0;background:#fafafa;">'
                . $rows . '</table>';
        }

        $reasonBlock = '';
        $rr = trim($rejectionReason);
        if ($rr !== '') {
            $safeR = nl2br(htmlspecialchars($rr, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $reasonBlock = '<div style="margin:0 0 20px;padding:14px 16px;background:#fef2f2;border-left:4px solid #dc2626;border-radius:8px;">'
                . '<p style="margin:0 0 6px;font-size:12px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:0.04em;">Message from our team</p>'
                . '<p style="margin:0;font-size:15px;line-height:1.55;color:#1e293b;">' . $safeR . '</p></div>';
        }

        $mail->Body = '<div style="font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;color:#1e293b;">'
            . '<p style="margin:0 0 16px;font-size:16px;line-height:1.5;">Dear ' . $safeName . ',</p>'
            . '<p style="margin:0 0 8px;font-size:15px;">Your application status is now</p>'
            . '<p style="margin:0 0 20px;font-size:18px;font-weight:700;color:#012F6B;">' . $safeStatus . '</p>'
            . $reasonBlock
            . $detailBlock
            . '<p style="margin:20px 0 0;font-size:14px;color:#475569;">Questions? Reply to this email.</p>'
            . '<p style="margin:16px 0 0;font-size:13px;color:#94a3b8;">Xander Global Scholars</p>'
            . '</div>';

        $plain = "Dear {$studentName},\n\nYour application status is now: {$statusLabel}\n\n";
        if ($rr !== '') {
            $plain .= "Message from our team:\n{$rr}\n\n";
        }
        if ($plainLines !== []) {
            $plain .= implode("\n", $plainLines) . "\n\n";
        }
        $plain .= "Questions? Reply to this email.\n\n— Xander Global Scholars";
        $mail->AltBody = $plain;

        return $mail->send();
    } catch (\Throwable $e) {
        error_log('[student_status_notify] email: ' . $e->getMessage());
        return false;
    }
}

/**
 * POST to Graph /messages; returns HTTP code and decoded JSON when possible.
 *
 * @return array{http:int,body:string,json:?array}
 */
function xander_whatsapp_graph_post(string $url, string $token, array $payload): array
{
    if (!function_exists('curl_init')) {
        return ['http' => 0, 'body' => '', 'json' => null];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = (string) $response;
    $decoded = json_decode($body, true);
    $json = is_array($decoded) ? $decoded : null;

    return ['http' => $code, 'body' => $body, 'json' => $json];
}

/**
 * True if Graph JSON indicates a delivered message id.
 */
function xander_whatsapp_response_has_message_id(?array $json): bool
{
    if (!$json || isset($json['error'])) {
        return false;
    }
    $mid = $json['messages'][0]['id'] ?? null;

    return is_string($mid) && $mid !== '';
}

/**
 * @return array{code:int,subcode:int,message:string}|null
 */
function xander_whatsapp_extract_error(?array $json): ?array
{
    if (!$json || !isset($json['error']) || !is_array($json['error'])) {
        return null;
    }
    $e = $json['error'];

    return [
        'code' => (int) ($e['code'] ?? 0),
        'subcode' => (int) ($e['error_subcode'] ?? $e['subcode'] ?? 0),
        'message' => trim((string) ($e['message'] ?? '')),
    ];
}

/**
 * User-facing explanation; logs raw API message in error_log elsewhere.
 */
function xander_whatsapp_user_hint(array $err): string
{
    $code = $err['code'] ?? 0;
    $sub = $err['subcode'] ?? 0;
    $msg = $err['message'] ?? '';

    if ($sub === 131026 || $code === 131026) {
        return 'This number is not on WhatsApp or is invalid for WhatsApp messaging.';
    }
    if ($sub === 131047) {
        return 'Outside the 24-hour session window. Use an approved template or wait for the applicant to message you first.';
    }
    if ($code === 132000 || $code === 132005) {
        return 'WhatsApp template is missing or not approved in Meta Business Manager.';
    }
    if ($code === 190 || stripos($msg, 'OAuth') !== false) {
        return 'WhatsApp API authentication failed — check the access token.';
    }
    if ($msg !== '') {
        return xander_notify_text_clip($msg, 280);
    }

    return 'WhatsApp could not send the message.';
}

/**
 * Invalid recipient / auth: do not retry with session text.
 */
function xander_whatsapp_error_is_fatal_no_text_fallback(?array $json, int $http): bool
{
    if ($http === 401 || $http === 403) {
        return true;
    }
    $e = xander_whatsapp_extract_error($json);
    if (!$e) {
        return false;
    }
    if ($e['code'] === 190) {
        return true;
    }
    if ($e['subcode'] === 131026) {
        return true;
    }

    return false;
}

/**
 * Template failed in a way that may work with a session text message (24h window).
 */
function xander_whatsapp_template_error_allows_text_fallback(?array $json): bool
{
    $e = xander_whatsapp_extract_error($json);
    if (!$e) {
        return false;
    }
    if ($e['code'] === 132000 || $e['code'] === 132005) {
        return true;
    }
    $m = strtolower($e['message']);

    return strpos($m, 'template') !== false
        && (strpos($m, 'does not exist') !== false || strpos($m, 'not found') !== false);
}

/**
 * Shared WhatsApp Cloud API: approved template first, then session text (24h window).
 *
 * @param array<int, string> $templateBodyTexts One entry per template variable (max 1024 chars each after sanitize)
 * @return array{sent:bool,method:string,error:string,detail:string}
 */
function xander_whatsapp_send_template_or_session(
    string $to,
    string $url,
    string $token,
    string $templateName,
    string $templateLang,
    int $paramCount,
    array $templateBodyTexts,
    string $sessionTextBody
): array {
    $template = trim((string) $templateName);
    $lang = $templateLang !== '' ? $templateLang : 'en_US';
    $tryTemplate = $template !== '';

    if ($tryTemplate) {
        $components = [];
        if ($paramCount > 0) {
            $bodyParams = [];
            for ($i = 0; $i < $paramCount; $i++) {
                $t = (string) ($templateBodyTexts[$i] ?? '');
                $bodyParams[] = ['type' => 'text', 'text' => xander_notify_text_clip(xander_whatsapp_sanitize_user_text($t), 1024)];
            }
            $components[] = ['type' => 'body', 'parameters' => $bodyParams];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $lang],
            ],
        ];
        if (!empty($components)) {
            $payload['template']['components'] = $components;
        }

        $res = xander_whatsapp_graph_post($url, $token, $payload);
        error_log('[whatsapp] template HTTP ' . $res['http'] . ' body: ' . $res['body']);

        if ($res['http'] >= 200 && $res['http'] < 300 && xander_whatsapp_response_has_message_id($res['json'])) {
            return ['sent' => true, 'method' => 'template', 'error' => '', 'detail' => ''];
        }

        if (xander_whatsapp_error_is_fatal_no_text_fallback($res['json'], $res['http'])) {
            $err = xander_whatsapp_extract_error($res['json']) ?? ['code' => 0, 'subcode' => 0, 'message' => 'HTTP ' . $res['http']];

            return [
                'sent' => false,
                'method' => 'template',
                'error' => xander_whatsapp_user_hint($err),
                'detail' => $res['body'],
            ];
        }

        if (!xander_whatsapp_template_error_allows_text_fallback($res['json']) && $res['http'] >= 400) {
            $err = xander_whatsapp_extract_error($res['json']) ?? ['code' => 0, 'subcode' => 0, 'message' => 'HTTP ' . $res['http']];

            return [
                'sent' => false,
                'method' => 'template',
                'error' => xander_whatsapp_user_hint($err),
                'detail' => $res['body'],
            ];
        }
    }

    $textPayload = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $to,
        'type' => 'text',
        'text' => [
            'preview_url' => false,
            'body' => xander_notify_text_clip($sessionTextBody, 4096),
        ],
    ];

    $res2 = xander_whatsapp_graph_post($url, $token, $textPayload);
    error_log('[whatsapp] text HTTP ' . $res2['http'] . ' body: ' . $res2['body']);

    if ($res2['http'] >= 200 && $res2['http'] < 300 && xander_whatsapp_response_has_message_id($res2['json'])) {
        return ['sent' => true, 'method' => 'text', 'error' => '', 'detail' => ''];
    }

    $err = xander_whatsapp_extract_error($res2['json']) ?? ['code' => 0, 'subcode' => 0, 'message' => 'HTTP ' . $res2['http']];

    return [
        'sent' => false,
        'method' => 'text',
        'error' => xander_whatsapp_user_hint($err),
        'detail' => $res2['body'],
    ];
}

/**
 * WhatsApp: try template if configured; otherwise or on template-not-found, try session text (24h window).
 *
 * @return array{sent:bool,method:string,error:string,detail:string}
 */
function xander_send_student_status_whatsapp(string $phoneRaw, string $studentName, string $statusLabel, array $ctx = [], string $rejectionReason = ''): array
{
    $empty = ['sent' => false, 'method' => '', 'error' => '', 'detail' => ''];

    $token = xander_env_get('WHATSAPP_ACCESS_TOKEN');
    $phoneId = xander_env_get('WHATSAPP_PHONE_NUMBER_ID');
    if ($token === '' || $phoneId === '') {
        if (function_exists('xander_whatsapp_env_debug_report_missing_credentials')) {
            xander_whatsapp_env_debug_report_missing_credentials();
        }
        $empty['error'] = 'WhatsApp is not configured (missing token or phone number ID). Check whatsapp_debug.log in the site root (or PHP temp folder).';
        $empty['detail'] = 'WHATSAPP_ACCESS_TOKEN or WHATSAPP_PHONE_NUMBER_ID empty after .env load';

        return $empty;
    }

    $defaultCc = xander_env_get('WHATSAPP_DEFAULT_COUNTRY_CODE');
    $defaultCcOrNull = $defaultCc !== '' ? $defaultCc : null;

    $to = xander_format_phone_for_whatsapp_e164($phoneRaw, $defaultCcOrNull);
    if ($to === null) {
        $empty['error'] = 'Invalid or incomplete phone number — add country code or set WHATSAPP_DEFAULT_COUNTRY_CODE in .env for national numbers.';
        $empty['detail'] = 'format failed for: ' . $phoneRaw;

        return $empty;
    }

    if (!function_exists('curl_init')) {
        $empty['error'] = 'Server has no cURL (enable php-curl).';
        $empty['detail'] = 'curl_init missing';

        return $empty;
    }

    $version = xander_env_get('META_GRAPH_VERSION');
    if ($version === '') {
        $version = 'v19.0';
    }
    $url = 'https://graph.facebook.com/' . rawurlencode($version) . '/' . rawurlencode((string) $phoneId) . '/messages';

    $pc = (int) XANDER_WHATSAPP_STATUS_TEMPLATE_PARAMS;
    $templateBodyTexts = [
        $studentName ?: 'Applicant',
        $statusLabel,
    ];
    if ($pc >= 3) {
        $detail = xander_whatsapp_detail_summary_for_template($ctx);
        if (trim($rejectionReason) !== '') {
            $detail = xander_notify_text_clip(
                "Message from our team:\n" . xander_whatsapp_sanitize_user_text(trim($rejectionReason)) . "\n\n" . $detail,
                1024
            );
        }
        $templateBodyTexts[] = $detail;
    }
    $templateBodyTexts = array_values(array_slice($templateBodyTexts, 0, max(0, $pc)));

    return xander_whatsapp_send_template_or_session(
        $to,
        $url,
        $token,
        XANDER_WHATSAPP_STATUS_TEMPLATE_NAME,
        XANDER_WHATSAPP_STATUS_TEMPLATE_LANG,
        $pc,
        $templateBodyTexts,
        xander_whatsapp_session_text_body($studentName, $statusLabel, $ctx, $rejectionReason)
    );
}

/**
 * Send only the channels requested by the staff UI (never throws).
 *
 * @return array{email:array{requested:bool,sent:?bool,error:string},whatsapp:array{requested:bool,sent:?bool,method:string,error:string}}|null null when no channels requested
 */
function xander_notify_student_status_change(
    mysqli $conn,
    string $table,
    int $id,
    string $flag,
    bool $sendEmail,
    bool $sendWhatsapp,
    string $rejectionReason = ''
) {
    if (!$sendEmail && !$sendWhatsapp) {
        return null;
    }

    xander_load_env_file();

    $emailOut = ['requested' => $sendEmail, 'sent' => null, 'error' => ''];
    $waOut = ['requested' => $sendWhatsapp, 'sent' => null, 'method' => '', 'error' => ''];

    $row = xander_fetch_applicant_for_notify($conn, $table, $id);
    if (!$row) {
        if ($sendEmail) {
            $emailOut['sent'] = false;
            $emailOut['error'] = 'Applicant record not found.';
        }
        if ($sendWhatsapp) {
            $waOut['sent'] = false;
            $waOut['error'] = 'Applicant record not found.';
        }

        return ['email' => $emailOut, 'whatsapp' => $waOut];
    }

    $label = xander_student_status_label($flag);

    if ($sendEmail) {
        $ctx = $row['email_context'] ?? [];
        if (!is_array($ctx)) {
            $ctx = [];
        }
        $to = trim((string) ($row['email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $emailOut['sent'] = false;
            $emailOut['error'] = 'No valid email address on file.';
        } else {
            $ok = xander_send_student_status_email(
                $to,
                $row['name'],
                $label,
                $ctx,
                $rejectionReason
            );
            $emailOut['sent'] = $ok;
            if ($ok) {
                error_log('[student_status_notify] Email sent to ' . $to . ' for id=' . $id);
            } else {
                $emailOut['error'] = 'Email could not be sent.';
            }
        }
    }

    if ($sendWhatsapp) {
        $waCtx = $row['email_context'] ?? [];
        if (!is_array($waCtx)) {
            $waCtx = [];
        }
        $r = xander_send_student_status_whatsapp($row['phone'], $row['name'], $label, $waCtx, $rejectionReason);
        $waOut['sent'] = $r['sent'];
        $waOut['method'] = $r['method'];
        $waOut['error'] = $r['error'];
        if ($r['sent']) {
            error_log('[student_status_notify] WhatsApp sent (' . $r['method'] . ') for id=' . $id);
        } elseif ($r['detail'] !== '') {
            error_log('[student_status_notify] WhatsApp failed for id=' . $id . ': ' . $r['detail']);
        }
    }

    return ['email' => $emailOut, 'whatsapp' => $waOut];
}
