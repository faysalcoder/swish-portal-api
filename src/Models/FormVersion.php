<?php
namespace App\Models;

class FormVersion extends BaseModel
{
    protected string $table = 'form_versions';

    protected array $fillable = [
        'form_id',   // relation to forms.id
        'file_url',  // stored file link
        'version',   // file version
        'created_at'
    ];
}
