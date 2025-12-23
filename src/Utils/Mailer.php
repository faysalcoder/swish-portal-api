<?php
// src/Utils/Mailer.php
namespace App\Utils;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    protected string $host;
    protected int $port;
    protected string $username;
    protected string $password;
    protected string $encryption;
    protected string $fromAddress;
    protected string $fromName;

    public function __construct()
    {
        $root = __DIR__ . '/../../';
        if (file_exists($root . '.env')) {
            \Dotenv\Dotenv::createImmutable($root)->safeLoad();
        }

        $this->host = $_ENV['MAIL_HOST'] ?? '';
        $this->port = (int)($_ENV['MAIL_PORT'] ?? 587);
        $this->username = $_ENV['MAIL_USERNAME'] ?? '';
        $this->password = $_ENV['MAIL_PASSWORD'] ?? '';
        $this->encryption = strtolower($_ENV['MAIL_ENCRYPTION'] ?? 'tls');
        $this->fromAddress = $_ENV['MAIL_FROM_ADDRESS'] ?? 'no-reply@example.com';
        $this->fromName = $_ENV['MAIL_FROM_NAME'] ?? 'App';
    }

    /**
     * Send email to one or many recipients with optional attachments.
     *
     * @param string|array $to Single email or array of emails (strings)
     * @param string $subject
     * @param string $htmlBody
     * @param string|null $altBody
     * @param array $attachments Each item: ['path' => '/abs/path/to/file', 'name' => 'filename.ext']
     * @return bool
     * @throws Exception
     */
    public function send($to, string $subject, string $htmlBody, ?string $altBody = null, array $attachments = []): bool
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->SMTPAuth = true;
            $mail->Username = $this->username;
            $mail->Password = $this->password;

            // Map common terms to PHPMailer constants
            if ($this->encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($this->encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                // Default fallback
                $mail->SMTPSecure = $this->encryption ?: PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->Port = $this->port;

            // From
            $mail->setFrom($this->fromAddress, $this->fromName);

            // Recipients
            if (is_array($to)) {
                foreach ($to as $recipient) {
                    // if recipient is array with keys [email, name]
                    if (is_array($recipient) && isset($recipient['email'])) {
                        $name = $recipient['name'] ?? '';
                        $mail->addAddress($recipient['email'], $name);
                    } else {
                        $mail->addAddress((string)$recipient);
                    }
                }
            } else {
                $mail->addAddress((string)$to);
            }

            // Attachments
            foreach ($attachments as $att) {
                if (!empty($att['path']) && file_exists($att['path'])) {
                    // 'name' optional
                    $name = $att['name'] ?? null;
                    $mail->addAttachment($att['path'], $name);
                }
            }

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $altBody ?? strip_tags($htmlBody);

            return $mail->send();
        } catch (Exception $e) {
            // Re-throw with PHPMailer's error info for caller logging
            throw new Exception('Mail error: ' . $mail->ErrorInfo . ' - ' . $e->getMessage());
        }
    }
}
