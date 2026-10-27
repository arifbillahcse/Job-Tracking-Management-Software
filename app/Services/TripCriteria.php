<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Request;

/**
 * Validated search / filter parameters for the trip list (same approach as UserCriteria).
 */
final class TripCriteria
{
    public const PER_PAGE = 25;

    private function __construct(
        public readonly string $q,
        public readonly int $foremanId,
        public readonly string $from,
        public readonly string $to,
        public readonly int $page,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $date = static function (string $value): string {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : '';
        };

        return new self(
            mb_substr(trim($request->queryStr('q')), 0, 100),
            max(0, $request->queryInt('foreman')),
            $date($request->queryStr('from')),
            $date($request->queryStr('to')),
            max(1, $request->queryInt('page', 1)),
        );
    }

    public function hasFilters(): bool
    {
        return $this->q !== '' || $this->foremanId > 0 || $this->from !== '' || $this->to !== '';
    }

    /** @return array<string,string|int> */
    public function linkParams(): array
    {
        return ['q' => $this->q, 'foreman' => $this->foremanId > 0 ? $this->foremanId : '', 'from' => $this->from, 'to' => $this->to];
    }
}
