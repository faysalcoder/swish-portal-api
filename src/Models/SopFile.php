<?php
namespace App\Models;

use PDO;
use Throwable;

/**
 * SopFile model
 *
 * This model ensures creates always insert a new row (no accidental mass-updates).
 */
class SopFile extends BaseModel
{
    protected string $table = 'sop_files';
    protected array $fillable = ['title','file_url','sop_id','timestamp','version','created_at'];

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get files by sop id (ordered newest first)
     *
     * @param int $sopId
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function bySop(int $sopId, int $limit = 100, int $offset = 0): array
    {
        return $this->where(['sop_id' => $sopId], $limit, $offset, ['timestamp' => 'DESC']);
    }

    /**
     * Get parent Sop record
     *
     * @return array|null
     */
    public function sop(): ?array
    {
        $sopModel = new Sop();
        if (isset($this->sop_id)) {
            return $sopModel->find((int)$this->sop_id);
        }
        return null;
    }

    /**
     * Create a SopFile with current timestamp and parent SOP's version.
     * Always inserts a new row.
     *
     * @param array $data
     * @return int|false Insert ID on success, false on failure.
     */
    public function createForSop(array $data)
    {
        if (empty($data['sop_id'])) {
            return false;
        }

        $sopModel = new Sop();
        $sop = $sopModel->find((int)$data['sop_id']);

        if ($sop && isset($sop['version'])) {
            $data['version'] = $sop['version'];
        } else {
            $data['version'] = '1.0';
        }

        // Ensure integer epoch timestamp
        $data['timestamp'] = isset($data['timestamp']) ? (int)$data['timestamp'] : time();
        // Add created_at human datetime (optional — good to have)
        if (!isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s', $data['timestamp']);
        }

        // Use BaseModel->create() which inserts a row (no updates)
        return $this->create($data);
    }

    /**
     * Create a SopFile with specific version and optional explicit timestamp.
     * Controller will call this and pass timestamp = strtotime($sop['updated_at']) to ensure exact match.
     *
     * Always performs an INSERT (will not alter existing rows).
     *
     * @param array $data
     * @param string $version
     * @param int|null $timestamp
     * @return int|false Insert ID or false
     */
    public function createWithVersion(array $data, string $version, ?int $timestamp = null)
    {
        if (empty($data['sop_id'])) {
            return false;
        }

        $data['version'] = $version;
        $data['timestamp'] = (int)($timestamp ?? time());
        if (!isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s', $data['timestamp']);
        }

        // Always insert a new row
        return $this->create($data);
    }

    /**
     * Update a single SopFile record by id. This updates only the specified row.
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function updateFile(int $id, array $data): bool
    {
        if (!isset($id) || $id <= 0) return false;

        if (!isset($data['timestamp'])) {
            $data['timestamp'] = time();
        } else {
            $data['timestamp'] = (int)$data['timestamp'];
        }

        // If updating created_at explicitly, make sure it's consistent
        if (isset($data['timestamp']) && !isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s', (int)$data['timestamp']);
        }

        return (bool)$this->update($id, $data);
    }

    /**
     * Get latest file for SOP
     *
     * @param int $sopId
     * @return array|null
     */
    public function getLatestForSop(int $sopId): ?array
    {
        $files = $this->bySop($sopId, 1, 0);
        return !empty($files) ? $files[0] : null;
    }

    /**
     * Static helper to update sop_files.version for a given sop id
     *
     * WARNING: mass-updates existing rows. Controller does NOT use this to preserve history.
     *
     * @param int $sopId
     * @param string|null $version
     * @return bool
     */
    public static function syncVersionForSop(int $sopId, $version = null): bool
    {
        try {
            $model = new static(); // get DB from an instance
            $sql = "UPDATE `sop_files` SET `version` = :version WHERE `sop_id` = :sop_id";
            $stmt = $model->db->prepare($sql);
            $stmt->bindValue(':version', $version);
            $stmt->bindValue(':sop_id', $sopId, PDO::PARAM_INT);
            return (bool)$stmt->execute();
        } catch (Throwable $e) {
            error_log("Failed to sync sop_files version for sop_id={$sopId}: " . $e->getMessage());
            return false;
        }
    }
}
