<?php
namespace App\Validators;

use App\Models\Room;

/**
 * Meeting payload validation.
 */
class MeetingValidator extends BaseValidator
{
    /**
     * Validate create payload.
     */
    public static function validateCreate(array $data): void
    {
        $errors = [];

        self::assert(!self::missing($data, 'title'), 'title', 'Title is required', $errors);
        self::assert(!self::missing($data, 'start_time'), 'start_time', 'Start time is required', $errors);
        self::assert(!self::missing($data, 'end_time'), 'end_time', 'End time is required', $errors);
        self::assert(!self::missing($data, 'room_id'), 'room_id', 'room_id is required', $errors);

        // times are valid datetimes
        if (!self::missing($data, 'start_time')) {
            self::assert(self::isValidDateTime($data['start_time']), 'start_time', 'Invalid start_time format (YYYY-mm-dd HH:MM:SS expected)', $errors);
        }
        if (!self::missing($data, 'end_time')) {
            self::assert(self::isValidDateTime($data['end_time']), 'end_time', 'Invalid end_time format (YYYY-mm-dd HH:MM:SS expected)', $errors);
        }

        // start < end
        if (!self::missing($data, 'start_time') && !self::missing($data, 'end_time')) {
            if (self::isValidDateTime($data['start_time']) && self::isValidDateTime($data['end_time'])) {
                self::assert(strtotime($data['start_time']) < strtotime($data['end_time']), 'time_range', 'start_time must be before end_time', $errors);
            }
        }

        // room existence (simple check)
        if (!self::missing($data, 'room_id')) {
            $roomModel = new Room();
            $room = $roomModel->find((int)$data['room_id']);
            self::assert($room !== null, 'room_id', 'Room not found', $errors);
        }

        self::throwIfErrors($errors);
    }

    public static function validateUpdate(array $data): void
    {
        $errors = [];

        if (isset($data['start_time'])) {
            self::assert(self::isValidDateTime($data['start_time']), 'start_time', 'Invalid start_time format', $errors);
        }
        if (isset($data['end_time'])) {
            self::assert(self::isValidDateTime($data['end_time']), 'end_time', 'Invalid end_time format', $errors);
        }
        if (isset($data['start_time']) && isset($data['end_time'])) {
            if (self::isValidDateTime($data['start_time']) && self::isValidDateTime($data['end_time'])) {
                self::assert(strtotime($data['start_time']) < strtotime($data['end_time']), 'time_range', 'start_time must be before end_time', $errors);
            }
        }
        if (isset($data['room_id'])) {
            self::assert(self::isIntegerish($data['room_id']), 'room_id', 'room_id must be integer', $errors);
            $roomModel = new Room();
            $room = $roomModel->find((int)$data['room_id']);
            self::assert($room !== null, 'room_id', 'Room not found', $errors);
        }

        self::throwIfErrors($errors);
    }

    protected static function isValidDateTime($value): bool
    {
        if (!is_string($value)) return false;
        $d = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
        return $d && $d->format('Y-m-d H:i:s') === $value;
    }
}
