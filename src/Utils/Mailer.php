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
        // load env (BaseController likely does this early, but safe here)
        $root = __DIR__ . '/../../';
        if (file_exists($root . '.env')) {
            \Dotenv\Dotenv::createImmutable($root)->load();
        }

        $this->host = $_ENV['MAIL_HOST'] ?? '';
        $this->port = (int)($_ENV['MAIL_PORT'] ?? 587);
        $this->username = $_ENV['MAIL_USERNAME'] ?? '';
        $this->password = $_ENV['MAIL_PASSWORD'] ?? '';
        $this->encryption = $_ENV['MAIL_ENCRYPTION'] ?? 'tls';
        $this->fromAddress = $_ENV['MAIL_FROM_ADDRESS'] ?? 'no-reply@example.com';
        $this->fromName = $_ENV['MAIL_FROM_NAME'] ?? 'App';
    }

    /**
     * Send a simple HTML email.
     * @param string $to
     * @param string $subject
     * @param string $htmlBody
     * @param string|null $altBody
     * @return bool
     * @throws Exception
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $altBody = null): bool
    {
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->SMTPAuth = true;
            $mail->Username = $this->username;
            $mail->Password = $this->password;
            $mail->SMTPSecure = $this->encryption ?: PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->port;

            // From
            $mail->setFrom($this->fromAddress, $this->fromName);

            // Recipient
            $mail->addAddress($to);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $altBody ?? strip_tags($htmlBody);

            return $mail->send();
        } catch (Exception $e) {
            // throw up so caller can log / handle
            throw new Exception('Mail error: ' . $mail->ErrorInfo);
        }
    }
}
