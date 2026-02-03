<?php
namespace App\Models;

/**
 * Sop model
 *
 * Note: this model is defensive about how the instance may be used:
 * - works if the instance has a public $id property (ActiveRecord style)
 * - also tolerates array-like attributes stored in $this->attributes (if your BaseModel uses that)
 */
class Sop extends BaseModel
{
    protected string $table = 'sops';
    protected array $fillable = [
        'title','version','file_url','wing_id','subw_id','visibility','created_at','updated_at'
    ];

    /**
     * Return the ID for this model instance if available (defensive).
     *
     * @return int|null
     */
    protected function getId(): ?int
    {
        // Common case: property exists on the instance
        if (isset($this->id) && (int)$this->id > 0) {
            return (int)$this->id;
        }

        // Some BaseModel implementations store attributes in an array property
        if (isset($this->attributes) && is_array($this->attributes) && !empty($this->attributes['id'])) {
            return (int)$this->attributes['id'];
        }

        // No id available
        return null;
    }

    /**
     * Get files for this SOP.
     *
     * Defensive: returns [] if no sop id is present.
     *
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function files(int $limit = 100, int $offset = 0): array
    {
        $sopFile = new SopFile();

        $sopId = $this->getId();
        if (!$sopId) {
            return [];
        }

        return $sopFile->bySop((int)$sopId, $limit, $offset);
    }

    /**
     * Sync all sop_files.version values to this SOP's version.
     * NOTE: This will mass-update sop_files for the SOP — controller intentionally
     * does not call this to preserve historical versions.
     *
     * @return bool
     */
    public function syncFilesVersion(): bool
    {
        $sopId = $this->getId();
        if (!$sopId) {
            return false;
        }
        return SopFile::syncVersionForSop((int)$sopId, $this->version ?? null);
    }

    /**
     * Update SOP's file_url (SOP table only)
     *
     * @return bool
     */
    public function updateFileUrl(string $fileUrl): bool
    {
        $sopId = $this->getId();
        if (!$sopId) {
            return false;
        }
        return (bool)$this->update($sopId, ['file_url' => $fileUrl]);
    }

    /**
     * Get the next major version (1.0 -> 2.0 -> 3.0)
     *
     * @return string
     */
    public function getNextMajorVersion(): string
    {
        $currentVersion = $this->version ?? '0.0';
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $currentVersion, $matches)) {
            $major = (int)($matches[1] ?? 0);
            $major++;
            return "{$major}.0";
        }

        return '1.0';
    }

    /**
     * Bump version to next major version on this SOP record only.
     *
     * @return bool
     */
    public function bumpVersion(): bool
    {
        $sopId = $this->getId();
        if (!$sopId) return false;
        $newVersion = $this->getNextMajorVersion();
        return (bool)$this->update($sopId, ['version' => $newVersion]);
    }

    /**
     * Get latest file (uses SopFile::bySop ordering)
     *
     * @return array|null
     */
    public function getLatestFile(): ?array
    {
        $files = $this->files(1, 0);
        return !empty($files) ? $files[0] : null;
    }
}
