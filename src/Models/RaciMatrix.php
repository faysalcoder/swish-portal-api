<?php
namespace App\Models;

class RaciMatrix extends BaseModel
{
    protected string $table = 'raci_matrices';
    protected array $fillable = ['wing_id','subw_id','title','status','deadline','month','created_at','updated_at'];

    public function byMonth(string $monthFirstDay, int $limit = 100, int $offset = 0): array
    {
        return $this->where(['month' => $monthFirstDay], $limit, $offset, ['id' => 'ASC']);
    }
}
