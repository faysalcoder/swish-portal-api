<?php
namespace App\Models;

class RaciRole extends BaseModel
{
    protected string $table = 'raci_roles';
    protected array $fillable = ['raci_id','role','user_id','created_at'];

    public function byRaci(int $raciId, int $limit = 100, int $offset = 0): array
    {
        return $this->where(['raci_id' => $raciId], $limit, $offset);
    }
}
