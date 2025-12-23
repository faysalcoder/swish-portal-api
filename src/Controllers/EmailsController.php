<?php
// src/Controllers/EmailsController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use App\Utils\Mailer;
use App\Models\Email;
use App\Models\EmailRecipient;
use App\Models\EmailAttachment;
use PDO;

/**
 * EmailsController
 *
 * Routes:
 *  GET    /api/v1/emails                  -> index()
 *  POST   /api/v1/emails                  -> store()
 *  GET    /api/v1/emails/{id}             -> show($id)
 *  DELETE /api/v1/emails/{id}             -> destroy($id)
 *  DELETE /api/v1/emails/bulk             -> bulkDestroy()
 *  PUT    /api/v1/emails/bulk-mark-sent   -> bulkMarkSent()
 *  GET    /api/v1/emails/stats            -> stats()
 *  GET    /api/v1/emails/report           -> report()
 *  GET    /api/v1/emails/attachments/{id} -> downloadAttachment($id)
 */
class EmailsController extends BaseController
{
    protected string $uploadDir;

    public function __construct()
    {
        parent::__construct();

        $root = __DIR__ . '/../../';
        $uploadDir = $_ENV['UPLOAD_DIR'] ?? 'storage/uploads';
        $this->uploadDir = rtrim($root, '/\\') . '/' . trim($uploadDir, '/\\') . '/email_attachments/';
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * GET /api/v1/emails
     */
    public function index()
    {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

        $emailModel = new Email();
        $list = $emailModel->recent($limit, $offset);

        return $this->sendJson(['success' => true, 'data' => $list], 200);
    }

    /**
     * POST /api/v1/emails
     * Create, store and send an email.
     * Accepts application/json or multipart/form-data.
     */
    public function store()
    {
        $pdo = null;
        try {
            // Read JSON body if present (BaseController::jsonInput())
            $rawJson = $this->jsonInput();
            // Prefer JSON fields when provided, otherwise fall back to $_POST
            $input = is_array($rawJson) && count($rawJson) > 0 ? $rawJson : $_POST;

            $subject = $input['subject'] ?? null;
            $email_body = $input['email_body'] ?? null;

            // --- normalize requester_id: keep NULL when empty/invalid to avoid inserting 0 ---
            $requester_id = null;
            $rawRequester = null;
            if (isset($input['requester_id'])) {
                $rawRequester = $input['requester_id'];
            } elseif (isset($_POST['requester_id'])) {
                $rawRequester = $_POST['requester_id'];
            }

            if ($rawRequester !== null && $rawRequester !== '') {
                $validated = filter_var($rawRequester, FILTER_VALIDATE_INT);
                if ($validated !== false && (int)$validated > 0) {
                    $requester_id = (int)$validated;
                } else {
                    $requester_id = null;
                }
            }

            // requester_email may come from JSON or form
            $requester_email = $input['requester_email'] ?? ($_POST['requester_email'] ?? null);
            $requester_email = $requester_email !== '' ? $requester_email : null;

            if (!$subject || !$email_body) {
                return $this->sendJson(['success' => false, 'message' => 'subject and email_body are required'], 400);
            }

            // Parse recipients from JSON or form fields (can be empty if send_mode uses requester)
            $recipients = [];

            if (isset($input['recipients']) && is_array($input['recipients'])) {
                foreach ($input['recipients'] as $r) {
                    if (is_string($r)) {
                        $recipients[] = ['email' => $r];
                    } elseif (is_array($r) && !empty($r['email'])) {
                        $recipients[] = ['email' => $r['email'], 'user_id' => isset($r['user_id']) ? (int)$r['user_id'] : null];
                    }
                }
            }

            if (empty($recipients) && !empty($_POST['recipients'])) {
                $raw = $_POST['recipients'];
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $r) {
                        if (is_string($r)) {
                            $recipients[] = ['email' => $r];
                        } elseif (is_array($r) && !empty($r['email'])) {
                            $recipients[] = ['email' => $r['email'], 'user_id' => $r['user_id'] ?? null];
                        }
                    }
                } else {
                    if (strpos($raw, ',') !== false) {
                        foreach (array_map('trim', explode(',', $raw)) as $e) {
                            if ($e !== '') $recipients[] = ['email' => $e];
                        }
                    } else {
                        $recipients[] = ['email' => $raw];
                    }
                }
            }

            if (empty($recipients) && !empty($_POST['recipients_array']) && is_array($_POST['recipients_array'])) {
                foreach ($_POST['recipients_array'] as $r) {
                    $recipients[] = ['email' => (string)$r];
                }
            }

            if (empty($recipients)) {
                if (!empty($input['recipient'])) $recipients[] = ['email' => $input['recipient']];
                elseif (!empty($input['to'])) $recipients[] = ['email' => $input['to']];
            }

            // Validate and normalize recipients (basic email validation)
            $recipients = array_values(array_filter(array_map(function ($r) {
                $email = trim((string)($r['email'] ?? ''));
                if ($email === '') return null;
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
                return ['email' => $email, 'user_id' => isset($r['user_id']) ? (int)$r['user_id'] : null];
            }, $recipients)));

            // ---------- determine send_mode ----------
            $send_mode_raw = $input['send_mode'] ?? ($_POST['send_mode'] ?? null);
            $send_to_recipients_flag = null;
            $send_to_requester_flag = null;

            // support boolean flags from form/json
            if (isset($input['send_to_recipients'])) {
                $send_to_recipients_flag = filter_var($input['send_to_recipients'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            } elseif (isset($_POST['send_to_recipients'])) {
                $send_to_recipients_flag = filter_var($_POST['send_to_recipients'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }

            if (isset($input['send_to_requester'])) {
                $send_to_requester_flag = filter_var($input['send_to_requester'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            } elseif (isset($_POST['send_to_requester'])) {
                $send_to_requester_flag = filter_var($_POST['send_to_requester'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }

            $send_mode = 'recipients'; // default for backwards compatibility

            if ($send_mode_raw && is_string($send_mode_raw)) {
                $sm = strtolower(trim($send_mode_raw));
                if (in_array($sm, ['recipients', 'requester', 'both'], true)) {
                    $send_mode = $sm;
                }
            } else {
                // use boolean flags if provided
                if ($send_to_recipients_flag !== null || $send_to_requester_flag !== null) {
                    $sToRecipients = $send_to_recipients_flag ?? false;
                    $sToRequester = $send_to_requester_flag ?? false;
                    if ($sToRecipients && $sToRequester) $send_mode = 'both';
                    elseif ($sToRequester && !$sToRecipients) $send_mode = 'requester';
                    elseif ($sToRecipients && !$sToRequester) $send_mode = 'recipients';
                    else $send_mode = 'recipients'; // default fallback
                }
            }

            // Get PDO connection and begin transaction
            $pdo = Connection::get();
            $pdo->beginTransaction();

            // Validate requester_id exists in users table; if not, set to null
            if ($requester_id !== null) {
                $stmtUser = $pdo->prepare('SELECT id, email FROM users WHERE id = ? LIMIT 1');
                $stmtUser->execute([$requester_id]);
                $found = $stmtUser->fetch(PDO::FETCH_ASSOC);
                if (!$found) {
                    // not found -> avoid FK violation by using NULL
                    $requester_id = null;
                } else {
                    // Fill requester_email from users.email if not provided
                    if (!$requester_email && !empty($found['email'])) {
                        $requester_email = $found['email'];
                    }
                }
            }

            // Validate recipients' user_ids against users table (defensive)
            if (!empty($recipients)) {
                $userCheckStmt = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
                foreach ($recipients as $idx => $r) {
                    $rawUid = $r['user_id'] ?? null;
                    if ($rawUid === null || $rawUid === '') {
                        $recipients[$idx]['user_id'] = null;
                        continue;
                    }

                    // numeric positive check
                    if (!is_numeric($rawUid) || (int)$rawUid <= 0) {
                        $recipients[$idx]['user_id'] = null;
                        continue;
                    }

                    $userCheckStmt->execute([(int)$rawUid]);
                    $userFound = $userCheckStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$userFound) {
                        // not found => null to avoid FK violation
                        $recipients[$idx]['user_id'] = null;
                    } else {
                        $recipients[$idx]['user_id'] = (int)$rawUid;
                    }
                }
            }

            // Ensure there is at least one final sending target according to send_mode
            $hasRecipientTargets = count($recipients) > 0;
            $hasRequesterTarget = !empty($requester_email) && filter_var($requester_email, FILTER_VALIDATE_EMAIL);

            $willSendToRecipients = in_array($send_mode, ['recipients', 'both'], true);
            $willSendToRequester = in_array($send_mode, ['requester', 'both'], true);

            if (!($willSendToRecipients && $hasRecipientTargets) && !($willSendToRequester && $hasRequesterTarget)) {
                // none of the requested destinations are actionable
                // Provide helpful message
                return $this->sendJson([
                    'success' => false,
                    'message' => 'No valid send targets. Either provide recipient emails, or provide requester_email/requester_id with a valid email, and set send_mode accordingly.'
                ], 400);
            }

            // Instantiate models (instance-style)
            $emailModel = new Email();
            $recipientModel = new EmailRecipient();
            $attachmentModel = new EmailAttachment();

            // 1) create email record
            $emailId = $emailModel->createEmail([
                'subject' => $subject,
                'requester_id' => $requester_id,
                'requester_email' => $requester_email,
                'email_body' => $email_body,
                'status' => 'pending'
            ]);

            // 2) save recipients (only if any provided)
            if (!empty($recipients)) {
                $recipientModel->addBulkRecipients($emailId, $recipients);
            }

            // 3) handle file uploads (attachments[])
            $attachmentsForMailer = [];
            if (!empty($_FILES['attachments']) && isset($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name'])) {
                $files = $_FILES['attachments'];
                for ($i = 0; $i < count($files['name']); $i++) {
                    if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;

                    $tmp = $files['tmp_name'][$i];
                    $origName = $files['name'][$i];
                    $mime = $files['type'][$i] ?? null;
                    $size = isset($files['size'][$i]) ? (int)$files['size'][$i] : null;

                    $storedName = $this->generateUniqueFilename($origName);
                    $dest = $this->uploadDir . $storedName;

                    if (!move_uploaded_file($tmp, $dest)) {
                        continue;
                    }

                    // store attachment metadata via instance model
                    $attachmentModel->addAttachment($emailId, $origName, $storedName, $mime, $size);

                    $attachmentsForMailer[] = [
                        'path' => $dest,
                        'name' => $origName
                    ];
                }
            }

            // 4) prepare recipients list for mailer according to send_mode
            $toEmails = [];

            if ($willSendToRecipients && $hasRecipientTargets) {
                foreach ($recipients as $r) {
                    if (!empty($r['email'])) $toEmails[] = $r['email'];
                }
            }

            if ($willSendToRequester && $hasRequesterTarget) {
                $toEmails[] = $requester_email;
            }

            // dedupe
            $toEmails = array_values(array_unique($toEmails));

            // If toEmails empty at this point, this should not happen due to earlier guard, but double-check
            if (count($toEmails) === 0) {
                // commit stored data, but indicate nothing was sent
                $pdo->commit();
                return $this->sendJson([
                    'success' => true,
                    'message' => 'Email stored but no recipients to send to (no valid addresses found).',
                    'email_id' => $emailId
                ], 200);
            }

            // 5) send email via Mailer
            $mailer = new Mailer();

            try {
                $mailer->send($toEmails, $subject, $email_body, null, $attachmentsForMailer);

                // Mark email as sent
                $emailModel->markSent($emailId);

                $pdo->commit();

                return $this->sendJson(['success' => true, 'message' => 'Email sent and stored', 'email_id' => $emailId], 200);
            } catch (\Throwable $e) {
                // mark failed but keep data persisted
                $emailModel->markFailed($emailId);
                $pdo->commit();

                return $this->sendJson(['success' => false, 'message' => 'Failed to send email', 'error' => $e->getMessage(), 'email_id' => $emailId], 500);
            }
        } catch (\Throwable $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $this->sendJson(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/v1/emails/{id}
     */
    public function show($id)
    {
        $emailModel = new Email();
        $recipientModel = new EmailRecipient();
        $attachmentModel = new EmailAttachment();

        $email = $emailModel->findById((int)$id);
        if (!$email) {
            return $this->sendJson(['success' => false, 'message' => 'Email not found'], 404);
        }
        $recipients = $recipientModel->getByEmailId((int)$id);
        $attachments = $attachmentModel->getByEmailId((int)$id);

        return $this->sendJson(['success' => true, 'data' => [
            'email' => $email,
            'recipients' => $recipients,
            'attachments' => $attachments
        ]], 200);
    }

    /**
     * DELETE /api/v1/emails/{id}
     */
    public function destroy($id)
    {
        $pdo = null;
        try {
            $pdo = Connection::get();
            $pdo->beginTransaction();
            
            // Check if email exists
            $emailModel = new Email();
            $email = $emailModel->findById((int)$id);
            
            if (!$email) {
                return $this->sendJson(['success' => false, 'message' => 'Email not found'], 404);
            }
            
            // Delete attachments from filesystem
            $attachmentModel = new EmailAttachment();
            $attachments = $attachmentModel->getByEmailId((int)$id);
            
            foreach ($attachments as $attachment) {
                $file = $this->uploadDir . $attachment['stored_name'];
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            
            // Delete from database
            $stmt = $pdo->prepare('DELETE FROM email_attachments WHERE email_id = ?');
            $stmt->execute([$id]);
            
            $stmt = $pdo->prepare('DELETE FROM email_recipients WHERE email_id = ?');
            $stmt->execute([$id]);
            
            $stmt = $pdo->prepare('DELETE FROM emails WHERE id = ?');
            $stmt->execute([$id]);
            
            $pdo->commit();
            
            return $this->sendJson(['success' => true, 'message' => 'Email deleted successfully'], 200);
        } catch (\Throwable $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $this->sendJson(['success' => false, 'message' => 'Failed to delete email', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/v1/emails/bulk
     */
    public function bulkDestroy()
    {
        $input = $this->jsonInput();
        $emailIds = $input['email_ids'] ?? [];
        
        if (empty($emailIds) || !is_array($emailIds)) {
            return $this->sendJson(['success' => false, 'message' => 'No emails selected'], 400);
        }
        
        $pdo = null;
        try {
            $pdo = Connection::get();
            $pdo->beginTransaction();
            
            $placeholders = str_repeat('?,', count($emailIds) - 1) . '?';
            
            // Delete attachments from filesystem
            $attachmentModel = new EmailAttachment();
            $stmt = $pdo->prepare("SELECT * FROM email_attachments WHERE email_id IN ($placeholders)");
            $stmt->execute($emailIds);
            $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($attachments as $attachment) {
                $file = $this->uploadDir . $attachment['stored_name'];
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            
            // Delete from database
            $stmt = $pdo->prepare("DELETE FROM email_attachments WHERE email_id IN ($placeholders)");
            $stmt->execute($emailIds);
            
            $stmt = $pdo->prepare("DELETE FROM email_recipients WHERE email_id IN ($placeholders)");
            $stmt->execute($emailIds);
            
            $stmt = $pdo->prepare("DELETE FROM emails WHERE id IN ($placeholders)");
            $stmt->execute($emailIds);
            
            $pdo->commit();
            
            return $this->sendJson(['success' => true, 'message' => 'Emails deleted successfully', 'count' => count($emailIds)], 200);
        } catch (\Throwable $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $this->sendJson(['success' => false, 'message' => 'Failed to delete emails', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/v1/emails/bulk-mark-sent
     */
    public function bulkMarkSent()
    {
        $input = $this->jsonInput();
        $emailIds = $input['email_ids'] ?? [];
        
        if (empty($emailIds) || !is_array($emailIds)) {
            return $this->sendJson(['success' => false, 'message' => 'No emails selected'], 400);
        }
        
        try {
            $pdo = Connection::get();
            $placeholders = str_repeat('?,', count($emailIds) - 1) . '?';
            
            $stmt = $pdo->prepare("UPDATE emails SET status = 'sent', sent_at = NOW() WHERE id IN ($placeholders)");
            $stmt->execute($emailIds);
            
            return $this->sendJson(['success' => true, 'message' => 'Emails marked as sent', 'count' => $stmt->rowCount()], 200);
        } catch (\Throwable $e) {
            return $this->sendJson(['success' => false, 'message' => 'Failed to update emails', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/v1/emails/stats
     */
    public function stats()
    {
        try {
            $pdo = Connection::get();
            
            $total = $pdo->query('SELECT COUNT(*) FROM emails')->fetchColumn();
            $sent = $pdo->query("SELECT COUNT(*) FROM emails WHERE status = 'sent'")->fetchColumn();
            $failed = $pdo->query("SELECT COUNT(*) FROM emails WHERE status = 'failed'")->fetchColumn();
            $pending = $pdo->query("SELECT COUNT(*) FROM emails WHERE status = 'pending'")->fetchColumn();
            
            $today = date('Y-m-d');
            $todayCount = $pdo->query("SELECT COUNT(*) FROM emails WHERE DATE(created_at) = '$today'")->fetchColumn();
            
            return $this->sendJson([
                'success' => true,
                'data' => [
                    'total' => (int)$total,
                    'sent' => (int)$sent,
                    'failed' => (int)$failed,
                    'pending' => (int)$pending,
                    'today' => (int)$todayCount,
                ]
            ], 200);
        } catch (\Throwable $e) {
            return $this->sendJson(['success' => false, 'message' => 'Failed to fetch stats', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/v1/emails/report
     */
    public function report()
    {
        try {
            $pdo = Connection::get();
            
            $stmt = $pdo->query("
                SELECT 
                    e.id,
                    e.subject,
                    e.requester_email,
                    e.status,
                    e.created_at,
                    e.sent_at,
                    COUNT(er.id) as recipient_count
                FROM emails e
                LEFT JOIN email_recipients er ON e.id = er.email_id
                GROUP BY e.id
                ORDER BY e.created_at DESC
            ");
            
            $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Generate CSV
            $csv = "ID,Subject,Requester Email,Status,Created At,Sent At,Recipient Count\n";
            
            foreach ($emails as $email) {
                $csv .= sprintf(
                    '"%s","%s","%s","%s","%s","%s",%s' . "\n",
                    $email['id'],
                    $email['subject'],
                    $email['requester_email'],
                    $email['status'],
                    $email['created_at'],
                    $email['sent_at'],
                    $email['recipient_count']
                );
            }
            
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="email-report-' . date('Y-m-d') . '.csv"');
            echo $csv;
            exit;
        } catch (\Throwable $e) {
            return $this->sendJson(['success' => false, 'message' => 'Failed to generate report', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/v1/emails/attachments/{id}  -- stream attachment
     */
    public function downloadAttachment($attachmentId)
    {
        $attachmentModel = new EmailAttachment();
        $att = $attachmentModel->findAttachment((int)$attachmentId);
        if (!$att) {
            return $this->sendJson(['success' => false, 'message' => 'Attachment not found'], 404);
        }

        $file = $this->uploadDir . $att['stored_name'];
        if (!file_exists($file)) {
            return $this->sendJson(['success' => false, 'message' => 'File missing on server'], 404);
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . ($att['mime_type'] ?? 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . basename($att['original_name']) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    /**
     * Small, local JSON responder.
     */
    private function sendJson(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload);
        exit;
    }

    /**
     * Unique filename generator for stored attachments.
     */
    private function generateUniqueFilename(string $orig): string
    {
        $ext = pathinfo($orig, PATHINFO_EXTENSION);
        $name = bin2hex(random_bytes(8));
        return $name . ($ext ? '.' . $ext : '');
    }
}