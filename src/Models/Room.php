<?php
namespace App\Models;

class Room extends BaseModel
{
    protected string $table = 'rooms';

    /**
     * Match DB columns exactly.
     * According to your schema:
     *  - id (auto increment)
     *  - name varchar(200)
     *  - room_img varchar(255) NULL
     *  - capacity smallint unsigned (default 0)
     *  - sitting text NULL
     *  - presentation tinyint(1) NOT NULL default 0
     *  - created_at timestamp
     *  - updated_at timestamp
     */
    protected array $fillable = [
        'name',
        'room_img',
        'capacity',
        'sitting',
        'presentation',
        'created_at',
        'updated_at',
    ];

    public function findByName(string $name): ?array
    {
        $rows = $this->where(['name' => $name], 1, 0);
        return $rows[0] ?? null;
    }
}
