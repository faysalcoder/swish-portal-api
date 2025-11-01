<?php
namespace App\Models;

class FormModel extends BaseModel
{
    protected string $table = 'forms';
    protected array $fillable = ['title','form_file','notes','last_update','user_id'];
}
