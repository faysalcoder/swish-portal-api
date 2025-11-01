<?php
namespace App\Utils;

/**
 * Very small paginator helper. Controllers should use model->where()/all with limit/offset,
 * and call Paginator::create to build metadata.
 */
class Paginator
{
    public static function create(int $total, int $limit, int $offset, array $items = []): array
    {
        $page = (int)floor($offset / max(1, $limit)) + 1;
        $totalPages = (int)ceil($total / max(1, $limit));
        return [
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'page' => $page,
                'total_pages' => $totalPages
            ],
            'items' => $items
        ];
    }
}
