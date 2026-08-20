<?php
namespace SOI\Core;

/**
 * Mailer - SMTP email sender using native PHP sockets
 * Supports TLS/SSL, authentication, HTML emails
 */
class Mailer {
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $encryption; // tls, ssl, none
    private string $fromEmail;
    private string $fromName;
    private string $lastError = '';

    public function __construct() {
        $this->host       = Database::getOption('smtp_host', '');
        $this->port       = (int) Database::getOption('smtp_port', 587);
        $this->user       = Database::getOption('smtp_user', '');
        $this->pass       = Database::getOption('smtp_pass', '');
        $this->encryption = Database::getOption('smtp_encryption', 'tls');
        $this->fromEmail  = Database::getOption('smtp_from_email', Database::getOption('admin_email', ''));
        $this->fromName   = Database::getOption('smtp_from_name', Database::getOption('site_name', 'SOI (School Of Interns) CMS'));
    }

    public function send(string $to, string $subject, string $body, string $toName = '', bool $isHtml = true): bool {
        if (empty($this->host) || empty($this->user)) {
            $this->lastError = 'SMTP not configured.';
            return false;
        }

        try {
            $protocol = '';
            if ($this->encryption === 'ssl') {
                $protocol = 'ssl://';
            }

            $socket = @fsockopen($protocol . $this->host, $this->port, $errno, $errstr, 15);
            if (!$socket) {
                $this->lastError = "Connection failed: $errstr ($errno)";
                return false;
            }

            stream_set_timeout($socket, 15);

            $this->readResponse($socket); // greeting

            // EHLO
            fwrite($socket, "EHLO " . gethostname() . "\r\n");
            $caps = $this->readResponse($socket);

            // STARTTLS
            if ($this->encryption === 'tls') {
                fwrite($socket, "STARTTLS\r\n");
                $this->readResponse($socket);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    $this->lastError = 'STARTTLS failed.';
                    fclose($socket);
                    return false;
                }
                fwrite($socket, "EHLO " . gethostname() . "\r\n");
                $this->readResponse($socket);
            }

            // AUTH LOGIN
            fwrite($socket, "AUTH LOGIN\r\n");
            $this->readResponse($socket);
            fwrite($socket, base64_encode($this->user) . "\r\n");
            $this->readResponse($socket);
            fwrite($socket, base64_encode($this->pass) . "\r\n");
            $authResp = $this->readResponse($socket);
            if (strpos($authResp, '235') === false) {
                $this->lastError = 'SMTP authentication failed.';
                fclose($socket);
                return false;
            }

            // Send email
            fwrite($socket, "MAIL FROM:<{$this->fromEmail}>\r\n");
            $this->readResponse($socket);

            fwrite($socket, "RCPT TO:<$to>\r\n");
            $this->readResponse($socket);

            fwrite($socket, "DATA\r\n");
            $this->readResponse($socket);

            $contentType = $isHtml ? 'text/html; charset=UTF-8' : 'text/plain; charset=UTF-8';
            $toLabel = $toName ? "\"$toName\" <$to>" : $to;
            $fromLabel = $this->fromName ? "\"" . $this->fromName . "\" <{$this->fromEmail}>" : $this->fromEmail;

            $headers  = "From: $fromLabel\r\n";
            $headers .= "To: $toLabel\r\n";
            $headers .= "Subject: $subject\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: $contentType\r\n";
            $headers .= "Date: " . date('r') . "\r\n";
            $headers .= "Message-ID: <" . uniqid('soi_', true) . "@" . gethostname() . ">\r\n";

            fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
            $this->readResponse($socket);

            fwrite($socket, "QUIT\r\n");
            fclose($socket);
            return true;

        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    private function readResponse($socket): string {
        $response = '';
        while ($line = fgets($socket, 1024)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $response;
    }

    public function getLastError(): string {
        return $this->lastError;
    }

    /** Test SMTP connection */
    public static function test(string $host, int $port, string $user, string $pass, string $encryption, string $to): array {
        $m = new self();
        $m->host = $host;
        $m->port = $port;
        $m->user = $user;
        $m->pass = $pass;
        $m->encryption = $encryption;
        $m->fromEmail = $user;
        $m->fromName = 'SOI (School Of Interns) CMS Test';

        $result = $m->send(
            $to,
            'SOI (School Of Interns) CMS — SMTP Test',
            '<h2>SMTP Test Successful!</h2><p>Your SOI (School Of Interns) CMS SMTP configuration is working correctly.</p>',
            '',
            true
        );

        return ['success' => $result, 'error' => $m->getLastError()];
    }
}
