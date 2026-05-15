<?php
/**
 * Pre-screening: email (with attachments) + WhatsApp Cloud API.
 *
 * Meta template (create in Business Manager → WhatsApp → Message templates):
 *   Name: xander_prescreening_received
 *   Category: UTILITY
 *   Language: English
 *   Body: Hello {{1}}, thank you for your pre-screening with Xander Global Scholars.
 *         Reference: {{2}}. Our team will review your answers and documents and contact you soon.
 *
 * If the template is not approved yet, session text is attempted (24h window only).
 * Documents are sent as separate WhatsApp document messages after the summary text.
 */
declare(strict_types=1);

require_once __DIR__ . '/mail_smtp.php';
require_once __DIR__ . '/env_load.php';
require_once __DIR__ . '/student_status_notify.php';

const XANDER_WHATSAPP_PRESCREENING_TEMPLATE_NAME = 'xander_prescreening_received';
const XANDER_WHATSAPP_PRESCREENING_TEMPLATE_LANG = 'en';
const XANDER_WHATSAPP_PRESCREENING_TEMPLATE_PARAMS = 2;

/** @return array<string, string> */
function xander_prescreening_question_labels(): array
{
    return [
        'education_level' => 'Highest level of education',
        'course_program' => 'Course or program to study',
        'country_interest' => 'Country of interest',
        'open_other_countries' => 'Open to other countries (India, Cyprus, Malta)',
        'budget_tuition' => 'Estimated tuition budget per year',
        'funds_application_visa' => 'Funds for application and visa fees',
        'sponsor' => 'Who will sponsor studies',
        'afford_deposit' => 'Can afford initial deposit and accommodation',
        'has_valid_passport' => 'Valid passport',
        'academic_docs_ready' => 'Academic documents ready',
        'english_level' => 'Level of English',
        'english_test_taken' => 'IELTS/TOEFL/Duolingo taken',
        'visa_denied' => 'Ever denied a visa',
        'planned_intake' => 'Planned intake',
        'ready_to_apply' => 'Ready to start application now',
    ];
}

/** @return array<string, string> */
function xander_prescreening_document_labels(): array
{
    return [
        'doc_valid_passport' => 'Valid Passport',
        'doc_degree_transcripts' => 'Degree / Academic Transcripts',
        'doc_high_school' => 'High School Certificate',
        'doc_cv_resume' => 'CV / Resume',
        'doc_recommendation' => 'Recommendation Letter(s)',
        'doc_personal_statement' => 'Personal Statement / Motivation Letter',
        'doc_english_certificate' => 'English Proficiency Certificate',
        'doc_birth_certificate' => 'Birth Certificate',
        'doc_payment_proof' => 'Application / Payment Proof',
    ];
}

function xander_prescreening_h(string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * @param array<string, mixed> $row
 */
function xander_prescreening_build_summary_lines(array $row): array
{
    $labels = xander_prescreening_question_labels();
    $lines = [];
    foreach ($labels as $key => $label) {
        $val = trim((string) ($row[$key] ?? ''));
        if ($val !== '') {
            $lines[] = $label . ': ' . $val;
        }
    }

    return $lines;
}

/**
 * @param array<string, mixed> $row
 */
function xander_prescreening_whatsapp_session_body(array $row, string $reference): string
{
    $name = xander_whatsapp_sanitize_user_text(trim((string) ($row['student_name'] ?? 'Student')));
    $parts = [
        '*Xander Global Scholars*',
        '*Pre-screening received*',
        '',
        'Hello ' . $name . ',',
        '',
        'Thank you. We received your quick pre-screening.',
        'Reference: *' . xander_whatsapp_sanitize_user_text($reference) . '*',
        '',
        '*Your answers*',
    ];
    foreach (xander_prescreening_build_summary_lines($row) as $line) {
        $parts[] = xander_whatsapp_sanitize_user_text($line);
    }
    $parts[] = '';
    $parts[] = 'Supporting documents will follow in separate messages.';
    $parts[] = '';
    $parts[] = '— Xander Global Scholars';

    return xander_notify_text_clip(implode("\n", $parts), 4096);
}

/**
 * @param array<string, mixed> $row
 */
function xander_prescreening_build_email_html(array $row, string $reference, bool $forAdmin): array
{
    $name = trim((string) ($row['student_name'] ?? ''));
    $email = trim((string) ($row['student_email'] ?? ''));
    $wa = trim((string) ($row['whatsapp_number'] ?? ''));

    $qRows = '';
    foreach (xander_prescreening_question_labels() as $key => $label) {
        $val = trim((string) ($row[$key] ?? ''));
        $qRows .= '<tr><td style="padding:6px;border:1px solid #ddd;"><strong>'
            . xander_prescreening_h($label)
            . '</strong></td><td style="padding:6px;border:1px solid #ddd;">'
            . xander_prescreening_h($val !== '' ? $val : '—')
            . '</td></tr>';
    }

    $docList = '';
    foreach (xander_prescreening_document_labels() as $key => $label) {
        $path = trim((string) ($row[$key] ?? ''));
        $docList .= '<li>' . xander_prescreening_h($label)
            . ($path !== '' ? ' — attached' : ' — not uploaded')
            . '</li>';
    }

    $intro = $forAdmin
        ? '<h2>New pre-screening submission</h2>'
        : '<p>Dear <strong>' . xander_prescreening_h($name) . '</strong>,</p>'
            . '<p>Thank you for completing the quick pre-screening with <strong>Xander Global Scholars</strong>. '
            . 'A copy of your answers and uploaded documents is attached for your records.</p>';

    $body = $intro . '
<p><strong>Reference:</strong> ' . xander_prescreening_h($reference) . '</p>
<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:12px 0;">
<tr><td style="padding:6px;border:1px solid #ddd;"><strong>Student name</strong></td><td style="padding:6px;border:1px solid #ddd;">' . xander_prescreening_h($name) . '</td></tr>
<tr><td style="padding:6px;border:1px solid #ddd;"><strong>Email</strong></td><td style="padding:6px;border:1px solid #ddd;">' . xander_prescreening_h($email) . '</td></tr>
<tr><td style="padding:6px;border:1px solid #ddd;"><strong>WhatsApp</strong></td><td style="padding:6px;border:1px solid #ddd;">' . xander_prescreening_h($wa) . '</td></tr>
</table>
<h3>Pre-screening answers</h3>
<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;">' . $qRows . '</table>
<h3>Documents</h3>
<ul>' . $docList . '</ul>
<p>Kind regards,<br><strong>Xander Global Scholars</strong></p>';

  $subject = $forAdmin
        ? 'Pre-screening – ' . ($name !== '' ? $name : $reference)
        : 'Your pre-screening submission – Xander Global Scholars';

    return ['subject' => $subject, 'body' => $body];
}

/**
 * @param array<string, string> $docPaths keyed by doc_* column => relative path
 * @return array<int, array{path:string, name:string}>
 */
function xander_prescreening_collect_attachments(array $docPaths): array
{
    $out = [];
    $labels = xander_prescreening_document_labels();
    foreach ($labels as $key => $label) {
        $rel = trim((string) ($docPaths[$key] ?? ''));
        if ($rel === '') {
            continue;
        }
        $abs = dirname(__DIR__) . '/' . ltrim(str_replace(['\\', '..'], ['/', ''], $rel), '/');
        if (is_file($abs) && is_readable($abs)) {
            $out[] = ['path' => $abs, 'name' => preg_replace('/[^a-zA-Z0-9._-]+/', '_', $label) . '_' . basename($abs)];
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $row DB row
 * @return array{email:array{admin:bool,student:bool},whatsapp:array{sent:bool,error:string,detail:string,docs_sent:int}}
 */
function xander_send_prescreening_notifications(array $row, string $reference, bool $skipStudentWhatsapp = false): array
{
    xander_load_env_file();

    $emailResult = ['admin' => false, 'student' => false];
    $waResult = ['sent' => false, 'error' => '', 'detail' => '', 'docs_sent' => 0];

    $docPaths = [];
    foreach (array_keys(xander_prescreening_document_labels()) as $key) {
        $docPaths[$key] = (string) ($row[$key] ?? '');
    }
    $attachments = xander_prescreening_collect_attachments($docPaths);

    $adminMail = xander_prescreening_build_email_html($row, $reference, true);
    try {
        $mail = xander_create_phpmailer();
        $mail->isHTML(true);
        $mail->clearAddresses();
        $mail->clearAttachments();
        $mail->addAddress('admissions@xanderglobalscholars.com');
        $mail->Subject = $adminMail['subject'];
        $mail->Body = $adminMail['body'];
        foreach ($attachments as $att) {
            $mail->addAttachment($att['path'], $att['name']);
        }
        $emailResult['admin'] = $mail->send();
    } catch (Throwable $e) {
        error_log('[prescreening_notify] admin email: ' . $e->getMessage());
    }

    $studentEmail = trim((string) ($row['student_email'] ?? ''));
    if ($studentEmail !== '' && filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
        $studentMail = xander_prescreening_build_email_html($row, $reference, false);
        try {
            $mail2 = xander_create_phpmailer_applicant_sender();
            $mail2->isHTML(true);
            $mail2->clearAddresses();
            $mail2->clearAttachments();
            $mail2->addAddress($studentEmail, trim((string) ($row['student_name'] ?? '')));
            $mail2->Subject = $studentMail['subject'];
            $mail2->Body = $studentMail['body'];
            foreach ($attachments as $att) {
                $mail2->addAttachment($att['path'], $att['name']);
            }
            $emailResult['student'] = $mail2->send();
        } catch (Throwable $e) {
            error_log('[prescreening_notify] student email: ' . $e->getMessage());
        }
    }

    $phoneRaw = trim((string) ($row['whatsapp_number'] ?? ''));
    if ($skipStudentWhatsapp) {
        $waResult['sent'] = true;
        $waResult['error'] = '';

        return ['email' => $emailResult, 'whatsapp' => $waResult];
    }
    if ($phoneRaw === '') {
        $waResult['error'] = 'WhatsApp number is required.';

        return ['email' => $emailResult, 'whatsapp' => $waResult];
    }

    $token = xander_env_get('WHATSAPP_ACCESS_TOKEN');
    $phoneId = xander_env_get('WHATSAPP_PHONE_NUMBER_ID');
    if ($token === '' || $phoneId === '') {
        $waResult['error'] = 'WhatsApp is not configured (check WHATSAPP_ACCESS_TOKEN and WHATSAPP_PHONE_NUMBER_ID in .env).';

        return ['email' => $emailResult, 'whatsapp' => $waResult];
    }

    $defaultCc = xander_env_get('WHATSAPP_DEFAULT_COUNTRY_CODE');
    $to = xander_format_phone_for_whatsapp_e164($phoneRaw, $defaultCc !== '' ? $defaultCc : null);
    if ($to === null) {
        $waResult['error'] = 'Invalid WhatsApp number — include country code or set WHATSAPP_DEFAULT_COUNTRY_CODE in .env.';

        return ['email' => $emailResult, 'whatsapp' => $waResult];
    }

    $version = xander_env_get('META_GRAPH_VERSION');
    if ($version === '') {
        $version = 'v19.0';
    }
    $messagesUrl = 'https://graph.facebook.com/' . rawurlencode($version) . '/' . rawurlencode((string) $phoneId) . '/messages';

    $studentName = trim((string) ($row['student_name'] ?? 'Student'));
    $templateTexts = [$studentName ?: 'Student', $reference];

    $textRes = xander_whatsapp_send_template_or_session(
        $to,
        $messagesUrl,
        $token,
        XANDER_WHATSAPP_PRESCREENING_TEMPLATE_NAME,
        XANDER_WHATSAPP_PRESCREENING_TEMPLATE_LANG,
        XANDER_WHATSAPP_PRESCREENING_TEMPLATE_PARAMS,
        $templateTexts,
        xander_prescreening_whatsapp_session_body($row, $reference)
    );

    if (!$textRes['sent']) {
        $waResult['error'] = $textRes['error'] !== '' ? $textRes['error'] : 'Could not send WhatsApp summary.';
        $waResult['detail'] = $textRes['detail'];

        return ['email' => $emailResult, 'whatsapp' => $waResult];
    }

    $labels = xander_prescreening_document_labels();
    foreach ($attachments as $att) {
        $mediaId = xander_whatsapp_upload_media_file((string) $phoneId, $token, $version, $att['path']);
        if ($mediaId === null) {
            continue;
        }
        $caption = xander_notify_text_clip('Document: ' . basename($att['name']), 1024);
        $sent = xander_whatsapp_send_document_message($messagesUrl, $token, $to, $mediaId, $caption, basename($att['path']));
        if ($sent) {
            $waResult['docs_sent']++;
        }
        usleep(400000);
    }

    $waResult['sent'] = true;

    return ['email' => $emailResult, 'whatsapp' => $waResult];
}

function xander_whatsapp_mime_for_path(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    return $map[$ext] ?? 'application/octet-stream';
}

function xander_whatsapp_upload_media_file(string $phoneId, string $token, string $version, string $filePath): ?string
{
    if (!is_file($filePath) || !function_exists('curl_init')) {
        return null;
    }

    $mime = xander_whatsapp_mime_for_path($filePath);
    $url = 'https://graph.facebook.com/' . rawurlencode($version) . '/' . rawurlencode($phoneId) . '/media';

    $post = [
        'messaging_product' => 'whatsapp',
        'type' => $mime,
        'file' => new CURLFile($filePath, $mime, basename($filePath)),
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ]);
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode((string) $response, true);
    if ($code >= 200 && $code < 300 && is_array($json) && !empty($json['id'])) {
        return (string) $json['id'];
    }
    error_log('[prescreening_notify] media upload HTTP ' . $code . ' ' . (string) $response);

    return null;
}

function xander_whatsapp_send_document_message(
    string $messagesUrl,
    string $token,
    string $to,
    string $mediaId,
    string $caption,
    string $filename
): bool {
    $payload = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $to,
        'type' => 'document',
        'document' => [
            'id' => $mediaId,
            'caption' => xander_notify_text_clip($caption, 1024),
            'filename' => xander_notify_text_clip($filename, 240),
        ],
    ];
    $res = xander_whatsapp_graph_post($messagesUrl, $token, $payload);

    return $res['http'] >= 200 && $res['http'] < 300 && xander_whatsapp_response_has_message_id($res['json']);
}
