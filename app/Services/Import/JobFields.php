<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * The columns of a job spreadsheet: one definition used by the import (header matching, validation),
 * the export (column order, headings) and the template / on-screen reference, so they can never disagree.
 *
 * "pattern" matches a header after it is lower-cased and stripped of everything but letters and digits, so
 * "Job #", "job#" and "JOB NUMBER" all mean job_number. The patterns deliberately include the spelling in the
 * client's own sheet ("1st availble date", "customer Fname").
 */
final class JobFields
{
    public const TEXT = 'text';
    public const DATE = 'date';
    public const MONEY = 'money';
    public const NUMBER = 'number';
    public const STATUS = 'status';
    public const PHONE = 'phone';

    /**
     * @var array<string,array{label:string,pattern:string,type:string,required:bool,financial:bool,workflow:bool,example:string,help:string,assign?:bool}>
     */
    private const FIELDS = [
        'job_number' => ['label' => 'Job #', 'pattern' => '/^job(no|num|number|id)?$/', 'type' => self::TEXT, 'required' => true, 'financial' => false, 'workflow' => false,
            'example' => 'JT-1001', 'help' => 'Unique. Letters, numbers and - _ / . only.'],
        'customer_first_name' => ['label' => 'Customer First Name', 'pattern' => '/^(customer)?(first|f)name$/', 'type' => self::TEXT, 'required' => true, 'financial' => false, 'workflow' => false,
            'example' => 'Ada', 'help' => ''],
        'customer_last_name' => ['label' => 'Customer Last Name', 'pattern' => '/^(customer)?(last|l)name$/', 'type' => self::TEXT, 'required' => true, 'financial' => false, 'workflow' => false,
            'example' => 'Smith', 'help' => ''],
        'customer_phone' => ['label' => 'Customer Phone', 'pattern' => '/^(customer|client)(phone|tel|mobile|cell)(number|no)?$/', 'type' => self::PHONE, 'required' => false, 'financial' => false, 'workflow' => false,
            'example' => '(555) 010-4477', 'help' => 'Optional.'],
        'pickup_date' => ['label' => 'Pickup Date', 'pattern' => '/^pickup(date)?$/', 'type' => self::DATE, 'required' => false, 'financial' => false, 'workflow' => false,
            'example' => '2026-11-01', 'help' => 'YYYY-MM-DD or MM/DD/YYYY.'],
        'first_available_date' => ['label' => '1st Available Date', 'pattern' => '/^(1st|first)?availa?ble(date)?$/', 'type' => self::DATE, 'required' => true, 'financial' => false, 'workflow' => false,
            'example' => '2026-12-01', 'help' => 'The first day the customer can accept delivery.'],
        'from_location' => ['label' => 'From', 'pattern' => '/^from(location)?$/', 'type' => self::TEXT, 'required' => true, 'financial' => false, 'workflow' => false,
            'example' => 'Austin, TX', 'help' => ''],
        'to_location' => ['label' => 'To', 'pattern' => '/^to(location)?$/', 'type' => self::TEXT, 'required' => true, 'financial' => false, 'workflow' => false,
            'example' => 'Denver, CO', 'help' => ''],
        'cf' => ['label' => 'A-CF', 'pattern' => '/^(cf|acf|actualcf|cubicfeet|cuft)$/', 'type' => self::NUMBER, 'required' => false, 'financial' => false, 'workflow' => false,
            'example' => '900', 'help' => 'Actual cubic feet (A-CF). The storage fee is calculated from it.'],
        'estimated_cf' => ['label' => 'E-CF', 'pattern' => '/^(ecf|estimatedcf)$/', 'type' => self::NUMBER, 'required' => false, 'financial' => false, 'workflow' => false,
            'example' => '850', 'help' => 'Estimated cubic feet.'],
        'estimated_total' => ['label' => 'E-Total', 'pattern' => '/^(etotal|estimatedtotal)$/', 'type' => self::MONEY, 'required' => false, 'financial' => true, 'workflow' => false,
            'example' => '3000', 'help' => 'Estimated total. Needs permission to edit financials.'],
        'deposit' => ['label' => 'Deposit', 'pattern' => '/^(deposit|depo)$/', 'type' => self::MONEY, 'required' => false, 'financial' => true, 'workflow' => false,
            'example' => '1000', 'help' => 'Needs permission to edit financials.'],
        'grand_total' => ['label' => 'Grand Total', 'pattern' => '/^grandtotal$/', 'type' => self::MONEY, 'required' => false, 'financial' => true, 'workflow' => false,
            'example' => '3500', 'help' => 'Stored as typed. Needs permission to edit financials.'],
        'bh_total' => ['label' => 'BH Total', 'pattern' => '/^bhtotal$/', 'type' => self::MONEY, 'required' => false, 'financial' => true, 'workflow' => false,
            'example' => '2500', 'help' => 'Stored as typed. Needs permission to edit financials.'],
        'paid' => ['label' => 'Paid', 'pattern' => '/^paid$/', 'type' => self::MONEY, 'required' => false, 'financial' => true, 'workflow' => false,
            'example' => '1250', 'help' => 'Amount paid so far. Needs permission to edit financials.'],
        'balance' => ['label' => 'Balance', 'pattern' => '/^(balance|balancedue)$/', 'type' => self::MONEY, 'required' => false, 'financial' => true, 'workflow' => false,
            'example' => '1200.50', 'help' => 'Amount the customer still owes. Needs permission to edit financials.'],
        'job_location' => ['label' => 'Job Location', 'pattern' => '/^(joblocation|location)$/', 'type' => self::TEXT, 'required' => false, 'financial' => false, 'workflow' => false,
            'example' => 'Main warehouse', 'help' => ''],
        'foreman' => ['label' => 'Foreman', 'pattern' => '/^(foreman|forman)$/', 'type' => self::TEXT, 'required' => false, 'financial' => false, 'workflow' => false, 'assign' => true,
            'example' => 'Joe', 'help' => 'Must be a foreman already on the Foremen page. Needs permission to manage trips.'],
        'trip_number' => ['label' => 'Trip Number', 'pattern' => '/^(trip|tripno|tripnumber|tripnum)$/', 'type' => self::TEXT, 'required' => false, 'financial' => false, 'workflow' => false, 'assign' => true,
            'example' => 'TRIP-1', 'help' => 'Must be a trip that already exists; the job is added to it and gets its foreman. Needs permission to manage trips.'],
        'job_lot' => ['label' => 'Job Lot', 'pattern' => '/^(joblot|lot)$/', 'type' => self::TEXT, 'required' => false, 'financial' => false, 'workflow' => false,
            'example' => 'A-12', 'help' => ''],
        'color' => ['label' => 'Color', 'pattern' => '/^colou?r$/', 'type' => self::TEXT, 'required' => false, 'financial' => false, 'workflow' => false,
            'example' => 'Blue', 'help' => ''],
        'status' => ['label' => 'Status', 'pattern' => '/^status$/', 'type' => self::STATUS, 'required' => false, 'financial' => false, 'workflow' => true,
            'example' => 'Booked', 'help' => 'Booked (default), In Transit, Delivered, On Hold or Cancelled. Needs permission to change status.'],
        'carrier_name' => ['label' => 'Carrier Name', 'pattern' => '/^(carrier|carriername)$/', 'type' => self::TEXT, 'required' => false, 'financial' => false, 'workflow' => true,
            'example' => 'Blue Ridge Haulers', 'help' => 'Optional (In Transit and Delivered jobs).'],
        'driver_name' => ['label' => 'Driver Name', 'pattern' => '/^(driver|drivername)$/', 'type' => self::TEXT, 'required' => false, 'financial' => false, 'workflow' => true,
            'example' => 'Marcus Reed', 'help' => 'Optional (In Transit and Delivered jobs).'],
        'driver_phone' => ['label' => 'Phone', 'pattern' => '/^(phone|driverphone|phonenumber|driversphone)$/', 'type' => self::PHONE, 'required' => false, 'financial' => false, 'workflow' => true,
            'example' => '(555) 010-2231', 'help' => 'The driver\'s phone. Optional (In Transit and Delivered jobs).'],
        'storage_fee' => ['label' => 'Storage Fee', 'pattern' => '/^(storagefee|storage)$/', 'type' => self::MONEY, 'required' => false, 'financial' => true, 'workflow' => false,
            'example' => '', 'help' => 'Leave empty: the storage fee is calculated automatically (CF × rate). A number here overrides it. Needs permission to edit financials.'],
        'loaded_date' => ['label' => 'Loaded Date', 'pattern' => '/^(loadeddate|loaded|loadingdate)$/', 'type' => self::DATE, 'required' => false, 'financial' => false, 'workflow' => true,
            'example' => '2026-10-18', 'help' => 'For In Transit and Delivered jobs. Optional.'],
        'delivered_on' => ['label' => 'Delivered Date', 'pattern' => '/^(delivereddate|deliverydate|delivered)$/', 'type' => self::DATE, 'required' => false, 'financial' => false, 'workflow' => true,
            'example' => '2026-10-20', 'help' => 'Required for Delivered. Not in the future.'],
        'notes' => ['label' => 'Notes', 'pattern' => '/^(notes|note|comments?)$/', 'type' => self::TEXT, 'required' => false, 'financial' => false, 'workflow' => false,
            'example' => 'Call before arrival', 'help' => ''],
    ];

    /**
     * @return array<string,array{label:string,pattern:string,type:string,required:bool,financial:bool,workflow:bool,example:string,help:string}>
     */
    public static function all(): array
    {
        return self::FIELDS;
    }

    public static function label(string $key): string
    {
        return self::FIELDS[$key]['label'] ?? $key;
    }

    /**
     * @return array<int,string> keys of the columns that must be present in an import file
     */
    public static function required(): array
    {
        return array_keys(array_filter(self::FIELDS, static fn (array $f): bool => $f['required']));
    }

    /** The column a header refers to, or null. */
    public static function match(string $header): ?string
    {
        $normalized = self::normalizeHeader($header);
        if ($normalized === '') {
            return null;
        }
        foreach (self::FIELDS as $key => $field) {
            if (preg_match($field['pattern'], $normalized) === 1) {
                return $key;
            }
        }
        return null;
    }

    public static function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($header));
    }
}
