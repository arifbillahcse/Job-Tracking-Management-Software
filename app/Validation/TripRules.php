<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Validation rules for the trip form and for one job row of it.
 */
final class TripRules
{
    /** @return array<string,string> */
    public static function header(?int $ignoreId = null): array
    {
        return [
            'trip_number' => 'required|code|max:40|unique:trips,trip_number' . ($ignoreId !== null ? ',' . $ignoreId : ''),
            'foreman_id' => 'required|integer|exists:foremen,id',
            'begins_on' => 'nullable|date',
            'ends_on' => 'nullable|date',
            'notes' => 'nullable|max:5000',
        ];
    }

    /** @return array<string,string> */
    public static function row(bool $withMoney): array
    {
        $rules = [
            'job_number' => 'required|max:40',
            'job_date' => 'nullable|date',
            'activity' => 'nullable|in:pickup,delivery,other',
            'from_location' => 'nullable|max:190',
            'to_location' => 'nullable|max:190',
            'foreman_id' => 'nullable|integer|exists:foremen,id',
            'bh' => 'nullable|max:100',
            'remark' => 'nullable|max:500',
        ];
        if ($withMoney) {
            foreach (['diesel', 'hotels', 'helper', 'misc', 'commission', 'extra'] as $field) {
                $rules[$field] = 'nullable|money|min_value:0|max_value:9999999999.99';
            }
        }
        return $rules;
    }

    /** @return array<string,string> */
    public static function labels(): array
    {
        return [
            'trip_number' => 'Trip number', 'foreman_id' => 'Foreman', 'begins_on' => 'Trip begins', 'ends_on' => 'Trip ends',
            'notes' => 'Notes', 'activity' => 'Activity', 'from_location' => 'From', 'to_location' => 'To',
            'job_number' => 'Job #', 'job_date' => 'Date', 'bh' => 'BH', 'diesel' => 'Diesel', 'hotels' => 'Hotels', 'helper' => 'Helper',
            'misc' => 'Misc', 'commission' => 'Commission', 'extra' => 'Extra', 'remark' => 'Remark',
        ];
    }
}
