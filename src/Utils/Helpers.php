<?php
namespace App\Utils;

class Helpers
{
    /**
     * Sanitize string by trimming and removing control chars.
     */
    public static function sanitizeString(?string $s): ?string
    {
        if ($s === null) return null;
        $s = trim($s);
        // remove ASCII control chars except newline/tab
        return preg_replace('/[^\P{C}\n\t]/u', '', $s);
    }

    /**
     * Parse month string (YYYY-MM or YYYY-MM-01) to canonical YYYY-MM-01 or return null.
     */
    public static function normalizeMonth(?string $month): ?string
    {
        if (!$month) return null;
        // allow YYYY-MM or YYYY-MM-01
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $month . '-01';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $month)) {
            $d = \DateTime::createFromFormat('Y-m-d', $month);
            if (!$d) return null;
            return $d->format('Y-m-01');
        }
        return null;
    }

    /**
     * Return current server time string in 'Y-m-d H:i:s'
     */
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Safe int casting: returns null if not integerish.
     */
    public static function safeInt($v): ?int
    {
        if ($v === null) return null;
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int)$v;
        return null;
    }
}
