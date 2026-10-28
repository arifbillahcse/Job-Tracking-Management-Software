<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Request;
use DateTimeImmutable;

/**
 * Validated search / filter / sort / page parameters for a job list. Everything that reaches SQL
 * comes out of this class already checked: sort columns and directions come from a whitelist,
 * dates are real dates, statuses are known values, page size is one of a fixed set.
 */
final class JobCriteria
{
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    /**
     * sort key => [SQL columns, needs financial access]
     *
     * @var array<string,array{0:array<int,string>,1:bool}>
     */
    private const SORTS = [
        'job'        => [['j.job_number'], false],
        'customer'   => [['j.customer_last_name', 'j.customer_first_name'], false],
        'pickup'     => [['j.pickup_date'], false],
        'available'  => [['j.first_available_date'], false],
        'from'       => [['j.from_location'], false],
        'to'         => [['j.to_location'], false],
        'cf'         => [['j.cf'], false],
        'status'     => [['j.status'], false],
        'carrier'    => [['j.carrier_name'], false],
        'driver'     => [['j.driver_name'], false],
        'dispatched' => [['j.loaded_date', 'j.in_transit_at'], false],
        'delivered'  => [['j.delivery_date', 'j.delivered_at'], false],
        'created'    => [['j.id'], false],
        'balance'    => [['j.balance'], true],
        'storage'    => [['j.storage_fee'], true],
        'foreman'    => [['af.name'], false],
        'trip'       => [['atr.trip_number'], false],
    ];

    /** Default ordering per section. */
    private const DEFAULT_SORT = [
        JobBucket::ALL => ['created', 'desc'],
        JobBucket::READY => ['available', 'asc'],
        JobBucket::LATER => ['available', 'asc'],
        JobBucket::TRANSIT => ['dispatched', 'desc'],
        JobBucket::DELIVERED => ['delivered', 'desc'],
    ];

    private function __construct(
        public readonly string $bucket,
        public readonly string $q,
        public readonly string $status,
        public readonly string $dateFrom,
        public readonly string $dateTo,
        public readonly string $fromLocation,
        public readonly string $toLocation,
        public readonly string $carrier,
        public readonly string $balance,
        public readonly string $sort,
        public readonly string $dir,
        public readonly int $page,
        public readonly int $perPage
    ) {
    }

    /** All defaults, no filters: used by dashboard counts. */
    public static function forBucket(string $bucket): self
    {
        [$sort, $dir] = self::DEFAULT_SORT[$bucket] ?? ['created', 'desc'];
        return new self($bucket, '', '', '', '', '', '', '', '', $sort, $dir, 1, self::defaultPerPage());
    }

    public static function fromRequest(Request $request, string $bucket, bool $canSeeFinancials): self
    {
        $text = static function (string $key, int $max) use ($request): string {
            $value = $request->query($key, '');
            $value = is_string($value) ? trim(preg_replace('/\s+/', ' ', $value) ?? '') : '';
            return mb_substr($value, 0, $max);
        };
        $date = static function (string $key) use ($request): string {
            $value = $request->query($key, '');
            if (!is_string($value)) {
                return '';
            }
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : '';
        };

        $status = $text('status', 20);
        if ($bucket !== JobBucket::ALL || !array_key_exists($status, (array) \App\Core\Config::get('statuses.statuses', []))) {
            $status = '';
        }

        $dateFrom = $date('from_date');
        $dateTo = $date('to_date');
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        // Filtering or sorting by money would reveal figures a user may not see.
        $balance = $canSeeFinancials && in_array($request->query('balance'), ['due', 'paid'], true) ? $request->queryStr('balance') : '';

        [$defaultSort, $defaultDir] = self::DEFAULT_SORT[$bucket] ?? ['created', 'desc'];
        $sort = $request->queryStr('sort', '');
        if (!isset(self::SORTS[$sort]) || (self::SORTS[$sort][1] && !$canSeeFinancials)) {
            $sort = $defaultSort;
            $dir = $defaultDir;
        } else {
            $dir = strtolower($request->queryStr('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        }

        $perPage = $request->queryInt('per_page', 0);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::defaultPerPage();
        }

        return new self(
            $bucket,
            $text('q', 100),
            $status,
            $dateFrom,
            $dateTo,
            $text('from', 100),
            $text('to', 100),
            $text('carrier', 100),
            $balance,
            $sort,
            $dir,
            max(1, $request->queryInt('page', 1)),
            $perPage
        );
    }

    public static function defaultPerPage(): int
    {
        $size = (int) SettingsService::get('page_size', '25');
        return in_array($size, self::PER_PAGE_OPTIONS, true) ? $size : 25;
    }

    /** ORDER BY clause. Always ends with a unique tie-breaker so pages never overlap or skip rows. */
    public function orderBySql(): string
    {
        $direction = $this->dir === 'desc' ? 'DESC' : 'ASC';
        $columns = array_map(static fn (string $c): string => $c . ' ' . $direction, self::SORTS[$this->sort][0]);
        $columns[] = 'j.id DESC';
        return 'ORDER BY ' . implode(', ', $columns);
    }

    /** Delivered jobs are filtered by delivery date; everything else by 1st available date. */
    public function dateColumn(): string
    {
        return $this->bucket === JobBucket::DELIVERED ? 'j.delivered_at' : 'j.first_available_date';
    }

    public function activeFilterCount(): int
    {
        return count(array_filter([
            $this->q, $this->status, $this->dateFrom, $this->dateTo,
            $this->fromLocation, $this->toLocation, $this->carrier, $this->balance,
        ], static fn (string $v): bool => $v !== ''));
    }

    public function hasFilters(): bool
    {
        return $this->activeFilterCount() > 0;
    }

    /** Filters that live inside the collapsible "more filters" panel (everything except the search box). */
    public function hasAdvancedFilters(): bool
    {
        return $this->activeFilterCount() - ($this->q !== '' ? 1 : 0) > 0;
    }

    /**
     * Query-string parameters that describe this view, without the page number (for building links).
     *
     * @return array<string,string|int>
     */
    public function linkParams(): array
    {
        $params = [
            'q' => $this->q, 'status' => $this->status, 'from_date' => $this->dateFrom, 'to_date' => $this->dateTo,
            'from' => $this->fromLocation, 'to' => $this->toLocation, 'carrier' => $this->carrier, 'balance' => $this->balance,
        ];
        [$defaultSort, $defaultDir] = self::DEFAULT_SORT[$this->bucket] ?? ['created', 'desc'];
        if ($this->sort !== $defaultSort || $this->dir !== $defaultDir) {
            $params['sort'] = $this->sort;
            $params['dir'] = $this->dir;
        }
        if ($this->perPage !== self::defaultPerPage()) {
            $params['per_page'] = $this->perPage;
        }
        return array_filter($params, static fn (string|int $v): bool => $v !== '');
    }

    /**
     * Link for a sortable column header: same filters, toggled direction, back on page 1.
     *
     * @return array<string,string|int>
     */
    public function sortParams(string $key): array
    {
        $params = $this->linkParams();
        $params['sort'] = $key;
        $params['dir'] = ($this->sort === $key && $this->dir === 'asc') ? 'desc' : 'asc';
        return $params;
    }

    public function withPage(int $page): self
    {
        return new self(
            $this->bucket, $this->q, $this->status, $this->dateFrom, $this->dateTo, $this->fromLocation, $this->toLocation,
            $this->carrier, $this->balance, $this->sort, $this->dir, $page, $this->perPage
        );
    }
}
