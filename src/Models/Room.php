<?php
namespace App\Models;

class Room extends BaseModel
{
    protected string $table = 'rooms';
    protected array $fillable = ['name','room_img','capacity','sitting','presentation','created_at','updated_at'];

    public function findByName(string $name): ?array
    {
        $rows = $this->where(['name' => $name], 1, 0);
        return $rows[0] ?? null;
    }
}
