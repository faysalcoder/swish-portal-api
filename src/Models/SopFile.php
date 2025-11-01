<?php
namespace App\Models;

class SopFile extends BaseModel
{
    protected string $table = 'sop_files';
    protected array $fillable = ['title','file_url','sop_id','timestamp'];

    public function bySop(int $sopId, int $limit = 100, int $offset = 0): array
    {
        return $this->where(['sop_id' => $sopId], $limit, $offset, ['timestamp' => 'DESC']);
    }
}
