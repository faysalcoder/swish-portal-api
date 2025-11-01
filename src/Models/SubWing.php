<?php
namespace App\Models;

class SubWing extends BaseModel
{
    protected string $table = 'sub_wings';
    protected array $fillable = ['wing_id','name','created_at','updated_at'];

    public function byWing(int $wingId, int $limit = 100, int $offset = 0): array
    {
        return $this->where(['wing_id' => $wingId], $limit, $offset);
    }
}
