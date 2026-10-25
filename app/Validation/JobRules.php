<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Validation rules for the job form. The same rule set will validate imported CSV rows (Phase 5).
 */
final class JobRules
{
    /**
     * @param bool $checkUnique false for bulk imports, which check duplicates themselves in one query
     * @return array<string,string>
     */
    public static function rules(?int $ignoreId = null, bool $withFinancials = false, bool $withCarrier = false, bool $checkUnique = true): array
    {
        $unique = $checkUnique ? '|unique:jobs,job_number' . ($ignoreId !== null ? ',' . $ignoreId : '') : '';

        $rules = [
            'job_number' => "required|code|max:40{$unique}",
            'customer_first_name' => 'required|max:100',
            'customer_last_name' => 'required|max:100',
            'customer_phone' => 'nullable|phone|max:30',
            'pickup_date' => 'nullable|date',
            'first_available_date' => 'required|date',
            'from_location' => 'required|max:190',
            'to_location' => 'required|max:190',
            'job_location' => 'nullable|max:190',
            'cf' => 'nullable|money|min_value:0|max_value:99999999.99',
            'notes' => 'nullable|max:5000',
        ];

        if ($withFinancials) {
            $rules['balance'] = 'nullable|money|min_value:0|max_value:9999999999.99';
            $rules['storage_fee'] = 'nullable|money|min_value:0|max_value:9999999999.99'; // import column: becomes the override
            $rules['storage_fee_override'] = 'nullable|money|min_value:0|max_value:9999999999.99';
        }

        if ($withCarrier) {
            $rules += self::dispatchRules();
        }

        return $rules;
    }

    /**
     * Carrier details are optional: a job moves to In Transit with one click, and these can be filled in while it is on the road.
     *
     * @return array<string,string>
     */
    public static function dispatchRules(): array
    {
        return [
            'carrier_name' => 'nullable|max:150',
            'driver_name' => 'nullable|max:150',
            'driver_phone' => 'nullable|phone|max:30',
        ];
    }

    /**
     * Dates that exist once a job has moved on, so they can be corrected when someone forgot to click on the day.
     *
     * @return array<string,string>
     */
    public static function dateRules(string $status): array
    {
        return match ($status) {
            'in_transit' => ['loaded_date' => 'nullable|date'],
            'delivered' => ['loaded_date' => 'nullable|date', 'delivery_date' => 'required|date'],
            default => [],
        };
    }

    /**
     * Cross-field checks on the corrected dates: nothing in the future, and delivery not before loading.
     *
     * @param array<string,mixed> $data validated
     * @return array<string,array<int,string>>
     */
    public static function dateProblems(array $data): array
    {
        $today = \App\Core\Clock::today();
        $errors = [];
        foreach (['loaded_date' => 'loaded', 'delivery_date' => 'delivery'] as $field => $word) {
            if (!empty($data[$field]) && (string) $data[$field] > $today) {
                $errors[$field][] = "The {$word} date cannot be in the future.";
            }
        }
        if (!empty($data['loaded_date']) && !empty($data['delivery_date']) && (string) $data['delivery_date'] < (string) $data['loaded_date']) {
            $errors['delivery_date'][] = 'The delivery date cannot be before the loaded date.';
        }
        return $errors;
    }

    /**
     * @return array<string,string>
     */
    public static function labels(): array
    {
        return [
            'job_number' => 'Job #',
            'customer_first_name' => 'First name',
            'customer_last_name' => 'Last name',
            'pickup_date' => 'Pickup date',
            'first_available_date' => '1st available date',
            'from_location' => 'From',
            'to_location' => 'To',
            'job_location' => 'Job location',
            'cf' => 'CF',
            'balance' => 'Balance',
            'storage_fee' => 'Storage fee',
            'storage_fee_override' => 'Storage fee override',
            'customer_phone' => 'Customer phone',
            'loaded_date' => 'Loaded date',
            'delivery_date' => 'Delivery date',
            'notes' => 'Notes',
            'carrier_name' => 'Carrier name',
            'driver_name' => 'Driver name',
            'driver_phone' => 'Phone number',
            'delivered_on' => 'Delivery date',
        ];
    }
}
