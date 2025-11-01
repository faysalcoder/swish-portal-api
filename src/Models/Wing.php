<?php
namespace App\Models;

class Wing extends BaseModel
{
    protected string $table = 'wings';
    protected array $fillable = ['name','icon','created_at','updated_at'];
}
