<?php
/**
 * Self-contained SMTP Mailer — no external dependencies (no Composer / PHPMailer).
 *
 * Supports:
 *  • STARTTLS (port 587) and implicit SSL (port 465)
 *  • AUTH LOGIN authentication
 *  • HTML + plain-text multipart emails
 *  • Cross-domain delivery (any sender domain → any recipient domain)
 *
 * Usage:
 *   $mailer = new SmtpMailer(SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD, SMTP_ENCRYPTION);
 *   $result = $mailer->send(
 *       'from@example.com', 'From Name',
 *       'to@example.com',
 *       'Subject line',
 *       '<h1>HTML body</h1>',
 *       'Plain-text body',          // optional
 *       'replyto@example.com'       // optional Reply-To
 *   );
 *   if ($result !== true) echo "Error: $result";
 */
class SmtpMailer
{
    private $host;
    private $port;
    private $username;
    private $password;
    private $encryption; // 'tls' or 'ssl'
    private $socket;
    private $log = [];
    private $timeout = 30;

    public function __construct($host, $port, $username, $password, $encryption = 'tls')
    {
        $this->host       = $host;
        $this->port       = (int) $port;
        $this->username   = $username;
        $this->password   = $password;
        $this->encryption = strtolower($encryption);
    }

    /**
     * Send an email.
     *
     * @param string $fromEmail   Sender email address
     * @param string $fromName    Sender display name
     * @param string $toEmail     Recipient email address
     * @param string $subject     Email subject
     * @param string $htmlBody    HTML version of the email
     * @param string $textBody    Plain-text version (optional)
     * @param string $replyTo     Reply-To address (optional)
     * @return true|string        true on success, error message on failure
     */
    public function send($fromEmail, $fromName, $toEmail, $subject, $htmlBody, $textBody = '', $replyTo = '')
    {
        // Try the configured encryption first; if TLS (STARTTLS) fails, auto-retry with SSL on 465
        $result = $this->doSend($fromEmail, $fromName, $toEmail, $subject, $htmlBody, $textBody, $replyTo);

        if ($result === true) {
            return true;
        }

        // If TLS failed, try SSL on port 465 as automatic fallback
        if ($this->encryption === 'tls') {
            error_log("SMTP TLS failed ({$result}), retrying with SSL on port 465...");
            $this->log[] = '--- Retrying with SSL/465 ---';
            $origPort = $this->port;
            $origEnc  = $this->encryption;
            $this->port       = 465;
            $this->encryption = 'ssl';
            $result = $this->doSend($fromEmail, $fromName, $toEmail, $subject, $htmlBody, $textBody, $replyTo);
            if ($result === true) {
                return true;
            }
            // Restore original settings
            $this->port       = $origPort;
            $this->encryption = $origEnc;
        }

        return $result;
    }

    /**
     * Internal: perform the actual SMTP send sequence.
     */
    private function doSend($fromEmail, $fromName, $toEmail, $subject, $htmlBody, $textBody, $replyTo)
    {
        try {
            // 1. Connect
            $connectResult = $this->connect();
            if ($connectResult !== true) {
                return $connectResult;
            }

            // 2. EHLO
            $this->sendCommand("EHLO " . gethostname(), 250);

            // 3. STARTTLS (for port 587 / tls encryption)
            if ($this->encryption === 'tls') {
                $this->sendCommand("STARTTLS", 220);

                // Try multiple crypto methods for maximum compatibility
                $cryptoMethods = [
                    STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                    STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT,
                    STREAM_CRYPTO_METHOD_ANY_CLIENT,
                ];
                $crypto = false;
                foreach ($cryptoMethods as $method) {
                    $crypto = @stream_socket_enable_crypto($this->socket, true, $method);
                    if ($crypto) break;
                }
                if (!$crypto) {
                    $this->close();
                    return 'SMTP Error: Failed to enable TLS encryption (STARTTLS). Your PHP/XAMPP may not support STARTTLS upgrades.';
                }
                // Must EHLO again after STARTTLS
                $this->sendCommand("EHLO " . gethostname(), 250);
            }

            // 4. AUTH LOGIN
            $this->sendCommand("AUTH LOGIN", 334);
            $this->sendCommand(base64_encode($this->username), 334);
            $this->sendCommand(base64_encode($this->password), 235);

            // 5. MAIL FROM
            $this->sendCommand("MAIL FROM:<{$fromEmail}>", 250);

            // 6. RCPT TO
            $this->sendCommand("RCPT TO:<{$toEmail}>", 250);

            // 7. DATA
            $this->sendCommand("DATA", 354);

            // 8. Build email message
            $message = $this->buildMessage($fromEmail, $fromName, $toEmail, $subject, $htmlBody, $textBody, $replyTo);

            // 9. Send message body (end with \r\n.\r\n)
            $this->sendCommand($message . "\r\n.", 250);

            // 10. QUIT
            $this->sendCommand("QUIT", 221);

            $this->close();
            return true;

        } catch (Exception $e) {
            $this->close();
            error_log("SMTP Mailer Error: " . $e->getMessage());
            error_log("SMTP Log: " . implode(" | ", $this->log));
            return 'SMTP Error: ' . $e->getMessage();
        }
    }

    /**
     * Open socket connection to the SMTP server.
     * Uses stream_socket_client for better TLS/SSL support than fsockopen.
     */
    private function connect()
    {
        $prefix = '';
        if ($this->encryption === 'ssl') {
            $prefix = 'ssl://';
        }

        $target = $prefix . $this->host . ':' . $this->port;

        // Use stream_socket_client — better TLS support than fsockopen on Windows/XAMPP
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ]);

        $this->socket = @stream_socket_client(
            $target,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$this->socket) {
            return "SMTP Connection Failed: {$errstr} (#{$errno}). Check SMTP_HOST/SMTP_PORT in config.php.";
        }

        // Set stream timeout
        stream_set_timeout($this->socket, $this->timeout);

        // Read server greeting
        $greeting = $this->readResponse();
        $this->log[] = "Server greeting: {$greeting}";

        if (substr($greeting, 0, 3) !== '220') {
            return "SMTP Error: Unexpected server greeting: {$greeting}";
        }

        return true;
    }

    /**
     * Send an SMTP command and validate the response code.
     */
    private function sendCommand($command, $expectedCode)
    {
        // Don't log passwords
        $logCommand = $command;
        if (strpos($command, base64_encode($this->password)) !== false) {
            $logCommand = '***PASSWORD***';
        } elseif (strpos($command, base64_encode($this->username)) !== false) {
            $logCommand = '***USERNAME***';
        }

        fwrite($this->socket, $command . "\r\n");
        $this->log[] = "C: {$logCommand}";

        $response = $this->readResponse();
        $this->log[] = "S: {$response}";

        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new Exception("Expected {$expectedCode}, got {$code}: {$response}");
        }

        return $response;
    }

    /**
     * Read multi-line SMTP response.
     */
    private function readResponse()
    {
        $response = '';
        while (true) {
            $line = fgets($this->socket, 4096);
            if ($line === false) {
                break;
            }
            $response .= $line;
            // If the 4th character is a space, this is the last line of the response
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return trim($response);
    }

    /**
     * Build the full email message with headers and body.
     */
    private function buildMessage($fromEmail, $fromName, $toEmail, $subject, $htmlBody, $textBody, $replyTo)
    {
        $boundary = 'YooNet_' . md5(uniqid(time()));
        $date     = date('r');
        $msgId    = '<' . uniqid('yoonet_', true) . '@' . gethostname() . '>';

        // Build headers
        $headers   = [];
        $headers[] = "Date: {$date}";
        $headers[] = "Message-ID: {$msgId}";
        $headers[] = "From: " . $this->encodeHeader($fromName) . " <{$fromEmail}>";
        $headers[] = "To: <{$toEmail}>";
        $headers[] = "Subject: " . $this->encodeHeader($subject);

        if (!empty($replyTo)) {
            $headers[] = "Reply-To: <{$replyTo}>";
        }

        $headers[] = "MIME-Version: 1.0";
        $headers[] = "X-Mailer: YooNet-Quest-System/1.0";
        $headers[] = "X-Priority: 3"; // Normal priority
        $headers[] = "X-MSMail-Priority: Normal";
        $headers[] = "Importance: Normal";
        $headers[] = "List-Unsubscribe: <mailto:" . $fromEmail . "?subject=Unsubscribe>";

        // If we have both HTML and text, use multipart/alternative
        if (!empty($textBody) && !empty($htmlBody)) {
            $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";

            $body  = "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $body .= $this->quotedPrintableEncode($textBody) . "\r\n\r\n";

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $body .= $this->quotedPrintableEncode($htmlBody) . "\r\n\r\n";

            $body .= "--{$boundary}--";
        } elseif (!empty($htmlBody)) {
            $headers[] = "Content-Type: text/html; charset=UTF-8";
            $headers[] = "Content-Transfer-Encoding: quoted-printable";
            $body = $this->quotedPrintableEncode($htmlBody);
        } else {
            $headers[] = "Content-Type: text/plain; charset=UTF-8";
            $headers[] = "Content-Transfer-Encoding: quoted-printable";
            $body = $this->quotedPrintableEncode($textBody);
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /**
     * Encode a header value for UTF-8 safety.
     */
    private function encodeHeader($value)
    {
        // Only encode if non-ASCII characters are present
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    /**
     * Simple quoted-printable encoding for email body.
     */
    private function quotedPrintableEncode($string)
    {
        return quoted_printable_encode($string);
    }

    /**
     * Close the socket connection.
     */
    private function close()
    {
        if ($this->socket && is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /**
     * Get the communication log (for debugging).
     */
    public function getLog()
    {
        return $this->log;
    }
}

/**
 * High-level helper: try SMTP first, fall back to mail(), return result.
 *
 * @param string $toEmail     Recipient email
 * @param string $toName      Recipient name
 * @param string $subject     Subject line
 * @param string $htmlBody    HTML body
 * @param string $textBody    Plain-text body
 * @param string $replyTo     Reply-To address (Quest Lead email)
 * @return array ['success' => bool, 'method' => string, 'error' => string]
 */
function smtp_send_email($toEmail, $toName, $subject, $htmlBody, $textBody = '', $replyTo = '')
{
    $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'noreply@yoonet-quest-system.com';
    $fromName  = defined('SMTP_FROM_NAME')  ? SMTP_FROM_NAME  : 'YooNet Quest System';

    // ── Method 1: SMTP via sockets (most reliable for cross-domain) ──────
    if (defined('SMTP_HOST') && defined('SMTP_USERNAME') && defined('SMTP_PASSWORD') && SMTP_PASSWORD !== '') {
        $encryption = defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls';
        $port       = defined('SMTP_PORT') ? SMTP_PORT : 587;

        $mailer = new SmtpMailer(SMTP_HOST, $port, SMTP_USERNAME, SMTP_PASSWORD, $encryption);
        $result = $mailer->send($fromEmail, $fromName, $toEmail, $subject, $htmlBody, $textBody, $replyTo);

        if ($result === true) {
            return ['success' => true, 'method' => 'smtp', 'error' => ''];
        }

        error_log("SMTP send failed, trying mail() fallback. Error: {$result}");
    } else {
        error_log("SMTP credentials not configured (SMTP_PASSWORD is empty). Trying mail() fallback.");
    }

    // ── Method 2: PHP mail() fallback (works on most production hosts) ───
    $headers   = [];
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/html; charset=UTF-8";
    $headers[] = "From: {$fromName} <{$fromEmail}>";
    if (!empty($replyTo)) {
        $headers[] = "Reply-To: <{$replyTo}>";
    }
    $headers[] = "X-Mailer: YooNet-Quest-System/1.0";

    $mailResult = @mail($toEmail, $subject, $htmlBody, implode("\r\n", $headers));

    if ($mailResult) {
        return ['success' => true, 'method' => 'mail', 'error' => ''];
    }

    return [
        'success' => false,
        'method'  => 'none',
        'error'   => 'Both SMTP and mail() delivery failed. Please check SMTP settings in includes/config.php (ensure SMTP_PASSWORD is a valid Gmail App Password).'
    ];
}
