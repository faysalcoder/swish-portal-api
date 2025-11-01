<?php
namespace App\Models;

class Sop extends BaseModel
{
    protected string $table = 'sops';
    protected array $fillable = ['title','version','file_url','wing_id','subw_id','visibility','created_at','updated_at'];
}
