<?php
namespace App\Models;

class Location extends BaseModel
{
    protected string $table = 'locations';
    protected array $fillable = ['country','address','type','status','created_at','updated_at'];
}
