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
use Throwable;

/**
 * EmailsController - improved version with:
 *  - robust recipients/user lookup
 *  - single/multiple attachment handling
 *  - better error reporting & logging
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
     *
     * Returns emails with recipients and attachments (bulk fetched to avoid N+1).
     */
    public function index()
    {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

        try {
            $emailModel = new Email();
            $emails = $emailModel->recent($limit, $offset);

            if (empty($emails)) {
                return $this->sendJson(['success' => true, 'data' => []], 200);
            }

            // Collect IDs
            $ids = array_values(array_map(function ($e) {
                return (int)$e['id'];
            }, $emails));

            // If something odd, just return emails as-is
            if (empty($ids)) {
                return $this->sendJson(['success' => true, 'data' => $emails], 200);
            }

            $pdo = Connection::get();

            // Build placeholders
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // Fetch recipients in bulk
            $recipientsStmt = $pdo->prepare("SELECT * FROM email_recipients WHERE email_id IN ($placeholders) ORDER BY id ASC");
            $recipientsStmt->execute($ids);
            $recipients = $recipientsStmt->fetchAll(PDO::FETCH_ASSOC);

            $recipientsMap = [];
            foreach ($recipients as $r) {
                $emailId = (int)$r['email_id'];
                if (!isset($recipientsMap[$emailId])) $recipientsMap[$emailId] = [];
                $recipientsMap[$emailId][] = $r;
            }

            // Fetch attachments in bulk
            $attachmentsStmt = $pdo->prepare("SELECT * FROM email_attachments WHERE email_id IN ($placeholders) ORDER BY id ASC");
            $attachmentsStmt->execute($ids);
            $attachments = $attachmentsStmt->fetchAll(PDO::FETCH_ASSOC);

            $attachmentsMap = [];
            foreach ($attachments as $a) {
                $emailId = (int)$a['email_id'];
                if (!isset($attachmentsMap[$emailId])) $attachmentsMap[$emailId] = [];
                $attachmentsMap[$emailId][] = $a;
            }

            // Attach recipients & attachments to each email record
            foreach ($emails as &$email) {
                $eid = (int)$email['id'];
                $email['recipients'] = $recipientsMap[$eid] ?? [];
                $email['attachments'] = $attachmentsMap[$eid] ?? [];
            }
            unset($email);

            return $this->sendJson(['success' => true, 'data' => $emails], 200);
        } catch (Throwable $e) {
            $errMsg = $e->getMessage() ?: ('Exception ' . get_class($e) . ' code ' . $e->getCode());
            error_log("EmailsController: index() error - " . $errMsg);
            return $this->sendJson(['success' => false, 'message' => 'Failed to fetch emails', 'error' => $errMsg], 500);
        }
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

            $subject = isset($input['subject']) ? trim((string)$input['subject']) : null;
            $email_body = isset($input['email_body']) ? (string)$input['email_body'] : null;

            // --- normalize requester_id ---
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
            $recipients = $this->parseRecipients($input);

            // basic validation: normalize and filter recipients list
            $recipients = array_values(array_filter(array_map(function ($r) {
                $email = trim((string)($r['email'] ?? ''));
                if ($email === '') return null;
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
                return ['email' => $email, 'user_id' => isset($r['user_id']) && $r['user_id'] !== '' ? (int)$r['user_id'] : null];
            }, $recipients)));

            // ---------- determine send_mode ----------
            $send_mode_raw = $input['send_mode'] ?? ($_POST['send_mode'] ?? null);
            $send_to_recipients_flag = null;
            $send_to_requester_flag = null;

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

            $send_mode = 'recipients'; // default

            if ($send_mode_raw && is_string($send_mode_raw)) {
                $sm = strtolower(trim($send_mode_raw));
                if (in_array($sm, ['recipients', 'requester', 'both'], true)) {
                    $send_mode = $sm;
                }
            } else {
                if ($send_to_recipients_flag !== null || $send_to_requester_flag !== null) {
                    $sToRecipients = $send_to_recipients_flag ?? false;
                    $sToRequester = $send_to_requester_flag ?? false;
                    if ($sToRecipients && $sToRequester) $send_mode = 'both';
                    elseif ($sToRequester && !$sToRecipients) $send_mode = 'requester';
                    elseif ($sToRecipients && !$sToRequester) $send_mode = 'recipients';
                    else $send_mode = 'recipients';
                }
            }

            // Get PDO connection and begin transaction
            $pdo = Connection::get();
            $pdo->beginTransaction();

            // Validate requester_id exists in users table; if not, set to null and attempt to fill requester_email by id
            if ($requester_id !== null) {
                $stmtUser = $pdo->prepare('SELECT id, email FROM users WHERE id = ? LIMIT 1');
                $stmtUser->execute([$requester_id]);
                $found = $stmtUser->fetch(PDO::FETCH_ASSOC);
                if (!$found) {
                    // not found -> avoid FK violation
                    $requester_id = null;
                } else {
                    if (!$requester_email && !empty($found['email'])) {
                        $requester_email = $found['email'];
                    }
                }
            }

            // Defensive: if recipients have no user_id, try lookup by email and fill user_id
            if (!empty($recipients)) {
                $userLookupStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                foreach ($recipients as $idx => $r) {
                    if (!empty($r['user_id'])) {
                        // verify exists
                        $verifyStmt = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
                        $verifyStmt->execute([(int)$r['user_id']]);
                        $v = $verifyStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$v) {
                            $recipients[$idx]['user_id'] = null;
                        } else {
                            $recipients[$idx]['user_id'] = (int)$r['user_id'];
                        }
                        continue;
                    }

                    // lookup by email
                    $userLookupStmt->execute([$r['email']]);
                    $foundUser = $userLookupStmt->fetch(PDO::FETCH_ASSOC);
                    if ($foundUser) {
                        $recipients[$idx]['user_id'] = (int)$foundUser['id'];
                    } else {
                        $recipients[$idx]['user_id'] = null;
                    }
                }
            }

            // Ensure at least one final sending target
            $hasRecipientTargets = count($recipients) > 0;
            $hasRequesterTarget = !empty($requester_email) && filter_var($requester_email, FILTER_VALIDATE_EMAIL);

            $willSendToRecipients = in_array($send_mode, ['recipients', 'both'], true);
            $willSendToRequester = in_array($send_mode, ['requester', 'both'], true);

            if (!($willSendToRecipients && $hasRecipientTargets) && !($willSendToRequester && $hasRequesterTarget)) {
                return $this->sendJson([
                    'success' => false,
                    'message' => 'No valid send targets. Either provide recipient emails, or provide requester_email/requester_id with a valid email, and set send_mode accordingly.'
                ], 400);
            }

            // Instantiate models
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

            if (!$emailId) {
                $pdo->rollBack();
                return $this->sendJson(['success' => false, 'message' => 'Failed to create email record'], 500);
            }

            // 2) save recipients (only if any provided)
            if (!empty($recipients)) {
                // ensure recipients array shape is ['email' => ..., 'user_id' => ...]
                $recipientModel->addBulkRecipients($emailId, $recipients);
            }

            // 3) handle file uploads (attachments[])
            $attachmentsForMailer = [];
            $filesList = $this->normalizeFilesArray($_FILES['attachments'] ?? null);

            foreach ($filesList as $file) {
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    // skip non-ok uploads
                    continue;
                }
                $tmp = $file['tmp_name'];
                $origName = $file['name'];
                $mime = $file['type'] ?? null;
                $size = isset($file['size']) ? (int)$file['size'] : null;

                $storedName = $this->generateUniqueFilename($origName);
                $dest = $this->uploadDir . $storedName;

                if (!is_uploaded_file($tmp) && !file_exists($tmp)) {
                    // sometimes tmp may be path from certain environments; still try to move but log if fails
                    error_log("EmailsController: upload tmp not found for {$origName}");
                }

                if (!@move_uploaded_file($tmp, $dest)) {
                    // fallback: try rename (if tmp was a temp path)
                    if (!@rename($tmp, $dest)) {
                        // could not move the uploaded file
                        error_log("EmailsController: Failed to move uploaded file {$origName} to {$dest}");
                        continue;
                    }
                }

                // store attachment metadata
                $attachmentModel->addAttachment($emailId, $origName, $storedName, $mime, $size);

                $attachmentsForMailer[] = [
                    'path' => $dest,
                    'name' => $origName
                ];
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

            // If toEmails empty at this point, commit stored data and return
            if (count($toEmails) === 0) {
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
                // Some Mailer implementations may return boolean false on failure rather than throwing.
                $sent = $mailer->send($toEmails, $subject, $email_body, null, $attachmentsForMailer);

                if ($sent === false) {
                    // Treat as failure but keep data persisted
                    $emailModel->markFailed($emailId);
                    $pdo->commit();

                    $msg = 'Mailer returned failure (false). Check mailer configuration and logs.';
                    error_log("EmailsController: {$msg} - email_id={$emailId} to: " . implode(',', $toEmails));
                    return $this->sendJson(['success' => false, 'message' => 'Failed to send email', 'error' => $msg, 'email_id' => $emailId], 500);
                }

                // If Mailer returned something else (true or object), treat as success
                $emailModel->markSent($emailId);
                $pdo->commit();

                return $this->sendJson(['success' => true, 'message' => 'Email sent and stored', 'email_id' => $emailId], 200);

            } catch (Throwable $e) {
                // mark failed but keep data persisted
                $emailModel->markFailed($emailId);
                $pdo->commit();

                // Some exceptions have empty message — include class and code for debugging
                $errMsg = $e->getMessage();
                if ($errMsg === '') {
                    $errMsg = 'Exception of type ' . get_class($e) . ' with code ' . $e->getCode();
                }
                error_log("EmailsController: Mailer exception sending email_id={$emailId} to: " . implode(',', $toEmails) . " - " . $errMsg);

                return $this->sendJson(['success' => false, 'message' => 'Failed to send email', 'error' => $errMsg, 'email_id' => $emailId], 500);
            }
        } catch (Throwable $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errMsg = $e->getMessage();
            if ($errMsg === '') {
                $errMsg = 'Exception of type ' . get_class($e) . ' with code ' . $e->getCode();
            }
            error_log("EmailsController: store() uncaught exception - " . $errMsg);
            return $this->sendJson(['success' => false, 'message' => 'Server error', 'error' => $errMsg], 500);
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

            $emailModel = new Email();
            $email = $emailModel->findById((int)$id);

            if (!$email) {
                return $this->sendJson(['success' => false, 'message' => 'Email not found'], 404);
            }

            $attachmentModel = new EmailAttachment();
            $attachments = $attachmentModel->getByEmailId((int)$id);

            foreach ($attachments as $attachment) {
                $file = $this->uploadDir . $attachment['stored_name'];
                if (file_exists($file)) {
                    @unlink($file);
                }
            }

            $stmt = $pdo->prepare('DELETE FROM email_attachments WHERE email_id = ?');
            $stmt->execute([$id]);

            $stmt = $pdo->prepare('DELETE FROM email_recipients WHERE email_id = ?');
            $stmt->execute([$id]);

            $stmt = $pdo->prepare('DELETE FROM emails WHERE id = ?');
            $stmt->execute([$id]);

            $pdo->commit();

            return $this->sendJson(['success' => true, 'message' => 'Email deleted successfully'], 200);
        } catch (Throwable $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errMsg = $e->getMessage() ?: ('Exception ' . get_class($e) . ' code ' . $e->getCode());
            error_log("EmailsController: destroy() error - " . $errMsg);
            return $this->sendJson(['success' => false, 'message' => 'Failed to delete email', 'error' => $errMsg], 500);
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

            $attachmentModel = new EmailAttachment();
            $stmt = $pdo->prepare("SELECT * FROM email_attachments WHERE email_id IN ($placeholders)");
            $stmt->execute($emailIds);
            $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($attachments as $attachment) {
                $file = $this->uploadDir . $attachment['stored_name'];
                if (file_exists($file)) {
                    @unlink($file);
                }
            }

            $stmt = $pdo->prepare("DELETE FROM email_attachments WHERE email_id IN ($placeholders)");
            $stmt->execute($emailIds);

            $stmt = $pdo->prepare("DELETE FROM email_recipients WHERE email_id IN ($placeholders)");
            $stmt->execute($emailIds);

            $stmt = $pdo->prepare("DELETE FROM emails WHERE id IN ($placeholders)");
            $stmt->execute($emailIds);

            $pdo->commit();

            return $this->sendJson(['success' => true, 'message' => 'Emails deleted successfully', 'count' => count($emailIds)], 200);
        } catch (Throwable $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errMsg = $e->getMessage() ?: ('Exception ' . get_class($e) . ' code ' . $e->getCode());
            error_log("EmailsController: bulkDestroy() error - " . $errMsg);
            return $this->sendJson(['success' => false, 'message' => 'Failed to delete emails', 'error' => $errMsg], 500);
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
        } catch (Throwable $e) {
            $errMsg = $e->getMessage() ?: ('Exception ' . get_class($e) . ' code ' . $e->getCode());
            error_log("EmailsController: bulkMarkSent() error - " . $errMsg);
            return $this->sendJson(['success' => false, 'message' => 'Failed to update emails', 'error' => $errMsg], 500);
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
        } catch (Throwable $e) {
            $errMsg = $e->getMessage() ?: ('Exception ' . get_class($e) . ' code ' . $e->getCode());
            error_log("EmailsController: stats() error - " . $errMsg);
            return $this->sendJson(['success' => false, 'message' => 'Failed to fetch stats', 'error' => $errMsg], 500);
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
                // sanitize double quotes
                $csv .= sprintf(
                    '"%s","%s","%s","%s","%s","%s",%s' . "\n",
                    $email['id'],
                    str_replace('"', '""', $email['subject']),
                    str_replace('"', '""', $email['requester_email']),
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
        } catch (Throwable $e) {
            $errMsg = $e->getMessage() ?: ('Exception ' . get_class($e) . ' code ' . $e->getCode());
            error_log("EmailsController: report() error - " . $errMsg);
            return $this->sendJson(['success' => false, 'message' => 'Failed to generate report', 'error' => $errMsg], 500);
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
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Unique filename generator for stored attachments.
     */
    private function generateUniqueFilename(string $orig): string
    {
        $ext = pathinfo($orig, PATHINFO_EXTENSION);
        try {
            $name = bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            // fallback
            $name = uniqid('', true);
        }
        return $name . ($ext ? '.' . $ext : '');
    }

    /**
     * Normalize $_FILES entry for attachments into an array of files
     * Accepts null, a single-file array, or the typical multi-file array
     */
    private function normalizeFilesArray($filesEntry): array
    {
        if (!$filesEntry) return [];

        // If we receive single file (not arrays for keys)
        if (!is_array($filesEntry['name'])) {
            return [
                [
                    'name' => $filesEntry['name'],
                    'type' => $filesEntry['type'] ?? null,
                    'tmp_name' => $filesEntry['tmp_name'],
                    'error' => $filesEntry['error'],
                    'size' => $filesEntry['size'] ?? null,
                ]
            ];
        }

        $normalized = [];
        for ($i = 0; $i < count($filesEntry['name']); $i++) {
            if ($filesEntry['name'][$i] === '') continue;
            $normalized[] = [
                'name' => $filesEntry['name'][$i],
                'type' => $filesEntry['type'][$i] ?? null,
                'tmp_name' => $filesEntry['tmp_name'][$i],
                'error' => $filesEntry['error'][$i],
                'size' => isset($filesEntry['size'][$i]) ? $filesEntry['size'][$i] : null,
            ];
        }
        return $normalized;
    }

    /**
     * Parse recipients from input (JSON or form)
     */
    private function parseRecipients(array $input): array
    {
        $recipients = [];

        if (isset($input['recipients']) && is_array($input['recipients'])) {
            foreach ($input['recipients'] as $r) {
                if (is_string($r)) {
                    $recipients[] = ['email' => $r];
                } elseif (is_array($r) && !empty($r['email'])) {
                    $recipients[] = ['email' => $r['email'], 'user_id' => $r['user_id'] ?? null];
                }
            }
            return $recipients;
        }

        // If recipients provided as JSON string in form body
        if (!empty($_POST['recipients'])) {
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
                return $recipients;
            } else {
                if (strpos($raw, ',') !== false) {
                    foreach (array_map('trim', explode(',', $raw)) as $e) {
                        if ($e !== '') $recipients[] = ['email' => $e];
                    }
                } else {
                    $recipients[] = ['email' => $raw];
                }
                return $recipients;
            }
        }

        if (!empty($_POST['recipients_array']) && is_array($_POST['recipients_array'])) {
            foreach ($_POST['recipients_array'] as $r) {
                $recipients[] = ['email' => (string)$r];
            }
            return $recipients;
        }

        // fallback single fields
        if (!empty($input['recipient'])) $recipients[] = ['email' => $input['recipient']];
        elseif (!empty($input['to'])) $recipients[] = ['email' => $input['to']];

        return $recipients;
    }
}
