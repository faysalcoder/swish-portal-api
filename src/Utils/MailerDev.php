<?php
// src/Utils/MailerDev.php
namespace App\Utils;

class MailerDev
{
    protected string $outDir;

    public function __construct(?string $outDir = null)
    {
        $root = __DIR__ . '/../../';
        $this->outDir = $outDir ?? ($root . 'storage/mails');
        if (!is_dir($this->outDir)) @mkdir($this->outDir, 0755, true);
    }

    public function send(string $to, string $subject, string $body): bool
    {
        $file = $this->outDir . '/mail_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.txt';
        $content = "To: {$to}\nSubject: {$subject}\n\n{$body}\n";
        file_put_contents($file, $content);
        return true;
    }
}
