<?php
namespace App\Models;

class FormModel extends BaseModel
{
    protected string $table = 'forms';

    protected array $fillable = [
        'title',
        'form_file',   // latest file URL
        'version',     // latest version (e.g. 1.0, 2.0, 3.0)
        'notes',
        'last_update',
        'user_id'
    ];
}
