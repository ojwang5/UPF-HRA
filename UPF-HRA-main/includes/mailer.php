<?php
declare(strict_types=1);

/* ─── Settings helpers ─── */
function get_setting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        try {
            $rows = db()->query("SELECT key, value FROM settings")->fetchAll();
            $cache = array_column($rows, 'value', 'key');
        } catch (\Throwable $e) { $cache = []; }
    }
    return $cache[$key] ?? $default;
}

function save_setting(string $key, string $value): void {
    db()->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute([$key, $value]);
}

function smtp_configured(): bool {
    return get_setting('smtp_host') !== '' && get_setting('smtp_from_email') !== '';
}

/* ─── Pure-PHP SMTP Mailer ─── */
function smtp_send_email(string $toEmail, string $toName, string $subject, string $htmlBody, string $plainBody = ''): array {
    $host      = get_setting('smtp_host');
    $port      = (int)(get_setting('smtp_port') ?: 587);
    $user      = get_setting('smtp_user');
    $pass      = get_setting('smtp_pass');
    $fromEmail = get_setting('smtp_from_email');
    $fromName  = get_setting('smtp_from_name') ?: 'UPF MDD System';
    $enc       = get_setting('smtp_encryption', 'tls');

    if (!$host || !$fromEmail) {
        return ['ok' => false, 'error' => 'SMTP not configured. Please set credentials in Settings.'];
    }
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => "Invalid recipient email: {$toEmail}"];
    }

    $connectHost = ($enc === 'ssl') ? 'ssl://' . $host : $host;
    $errno = 0; $errstr = '';
    $conn = @fsockopen($connectHost, $port, $errno, $errstr, 15);
    if (!$conn) {
        return ['ok' => false, 'error' => "Cannot connect to SMTP {$host}:{$port} — {$errstr}"];
    }

    $log = [];
    $read = function() use ($conn, &$log): string {
        $resp = '';
        while (!feof($conn)) {
            $line = fgets($conn, 512);
            $resp .= $line;
            $log[] = '< ' . trim($line);
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $resp;
    };
    $write = function(string $cmd) use ($conn, &$log): void {
        $log[] = '> ' . $cmd;
        fputs($conn, $cmd . "\r\n");
    };

    $r = $read(); // greeting
    if (!str_starts_with($r, '2')) { fclose($conn); return ['ok'=>false,'error'=>'SMTP greeting failed: '.$r]; }

    $write("EHLO " . ($_SERVER['HTTP_HOST'] ?? 'mdd.upf.local'));
    $r = $read();
    if (!str_starts_with($r, '2')) {
        $write("HELO " . ($_SERVER['HTTP_HOST'] ?? 'mdd.upf.local'));
        $read();
    }

    // STARTTLS upgrade (port 587)
    if ($enc === 'tls') {
        $write("STARTTLS");
        $r = $read();
        if (!str_starts_with($r, '2')) { fclose($conn); return ['ok'=>false,'error'=>'STARTTLS failed: '.$r]; }
        if (!stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($conn); return ['ok'=>false,'error'=>'TLS negotiation failed'];
        }
        $write("EHLO " . ($_SERVER['HTTP_HOST'] ?? 'mdd.upf.local'));
        $read();
    }

    // Auth
    if ($user && $pass) {
        $write("AUTH LOGIN");
        $read();
        $write(base64_encode($user));
        $read();
        $write(base64_encode($pass));
        $r = $read();
        if (!str_starts_with($r, '2')) { fclose($conn); return ['ok'=>false,'error'=>'SMTP auth failed: '.$r]; }
    }

    // Envelope
    $write("MAIL FROM:<{$fromEmail}>");
    $r = $read();
    if (!str_starts_with($r, '2')) { fclose($conn); return ['ok'=>false,'error'=>'MAIL FROM rejected: '.$r]; }

    $write("RCPT TO:<{$toEmail}>");
    $r = $read();
    if (!str_starts_with($r, '2')) { fclose($conn); return ['ok'=>false,'error'=>'RCPT TO rejected: '.$r]; }

    $write("DATA");
    $r = $read();
    if (!str_starts_with($r, '3')) { fclose($conn); return ['ok'=>false,'error'=>'DATA rejected: '.$r]; }

    // Build MIME message
    $boundary = '=_' . md5(uniqid('', true));
    $plain = $plainBody ?: strip_tags(str_replace(['<br>','<br/>','</p>','</div>'], "\n", $htmlBody));
    $toHeader   = $toName ? '"'.str_replace('"','', $toName).'" <'.$toEmail.'>' : $toEmail;
    $fromHeader = $fromName ? '"'.str_replace('"','', $fromName).'" <'.$fromEmail.'>' : $fromEmail;

    $headers  = "Date: " . date('r') . "\r\n";
    $headers .= "From: {$fromHeader}\r\n";
    $headers .= "To: {$toHeader}\r\n";
    $headers .= "Subject: " . mb_encode_mimeheader($subject, 'UTF-8', 'B') . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: UPF-MDD/1.0\r\n";
    $headers .= "\r\n";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($plain) . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($htmlBody) . "\r\n";
    $body .= "--{$boundary}--\r\n";

    // RFC 821: lines starting with '.' must be doubled
    $message = $headers . preg_replace('/^\.$/m', '..', $body);
    fputs($conn, $message . "\r\n.\r\n");
    $r = $read();
    if (!str_starts_with($r, '2')) { fclose($conn); return ['ok'=>false,'error'=>'Message rejected: '.$r]; }

    $write("QUIT");
    fclose($conn);

    return ['ok' => true];
}

/* ─── Batch send to a list of employees ─── */
function send_communication(int $commId, array $employees, PDO $pdo): void {
    $total = count($employees);
    $delivered = 0; $failed = 0; $errors = [];

    $comm = $pdo->query("SELECT * FROM communications WHERE id={$commId}")->fetch();
    if (!$comm) return;

    // Build HTML template
    $htmlBody = nl2br(htmlspecialchars($comm['body']));
    $htmlEmail = email_html_template($comm['subject'], $htmlBody);

    foreach ($employees as $emp) {
        $email = trim($emp['email'] ?? '');
        if (!$email) { $failed++; $errors[] = ($emp['full_name']??'Unknown').': no email address'; continue; }
        $result = smtp_send_email($email, $emp['full_name'] ?? '', $comm['subject'], $htmlEmail, $comm['body']);
        if ($result['ok']) { $delivered++; }
        else { $failed++; $errors[] = ($emp['full_name']??$email).': '.$result['error']; }
    }

    $status = ($failed === 0) ? 'sent' : ($delivered === 0 ? 'failed' : 'partial');
    $pdo->prepare("UPDATE communications SET status=?, total_recipients=?, delivered=?, failed=?, error_log=? WHERE id=?")
        ->execute([$status, $total, $delivered, $failed, $errors ? implode("\n", $errors) : null, $commId]);
}

/* ─── Branded HTML email wrapper ─── */
function email_html_template(string $subject, string $htmlBody): string {
    return <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><style>
body{margin:0;padding:0;background:#f1f4f9;font-family:Arial,sans-serif}
.wrap{max-width:600px;margin:24px auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1)}
.head{background:linear-gradient(135deg,#1f3559,#15243d);padding:20px 28px;border-bottom:3px solid #d4a017;display:flex;align-items:center;gap:14px}
.head-title{color:#fff;font-size:16px;font-weight:700;letter-spacing:1px}
.head-sub{color:rgba(255,255,255,.7);font-size:11px;margin-top:2px}
.body{padding:28px;color:#1a2236;font-size:14px;line-height:1.6}
.footer{background:#f8f9fc;padding:14px 28px;font-size:11px;color:#94a3b8;border-top:1px solid #e5e9f0;text-align:center}
</style></head>
<body>
<div class="wrap">
  <div class="head">
    <div>
      <div class="head-title">UGANDA POLICE FORCE</div>
      <div class="head-sub">MDD Management System — Official Communication</div>
    </div>
  </div>
  <div class="body">{$htmlBody}</div>
  <div class="footer">
    This is an official communication from the UPF MDD Management System.<br>
    &copy; Uganda Police Force &mdash; Protect &amp; Serve
  </div>
</div>
</body></html>
HTML;
}
