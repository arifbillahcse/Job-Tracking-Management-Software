<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Page arithmetic for list screens. The requested page is clamped to a valid range.
 */
final class Paginator
{
    public readonly int $page;
    public readonly int $lastPage;

    public function __construct(public readonly int $total, public readonly int $perPage, int $requestedPage)
    {
        $this->lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $this->page = min(max(1, $requestedPage), $this->lastPage);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** 1-based index of the first and last row on this page, for "Showing 26-50 of 61". */
    public function from(): int
    {
        return $this->total === 0 ? 0 : $this->offset() + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->offset() + $this->perPage);
    }

    /**
     * Page numbers to render: first, last and a window around the current page; null marks a gap ("...").
     *
     * @return array<int,int|null>
     */
    public function window(int $radius = 2): array
    {
        $pages = [];
        for ($p = 1; $p <= $this->lastPage; $p++) {
            if ($p === 1 || $p === $this->lastPage || abs($p - $this->page) <= $radius) {
                $pages[] = $p;
            }
        }

        $out = [];
        $previous = 0;
        foreach ($pages as $p) {
            if ($p - $previous > 1) {
                $out[] = null;
            }
            $out[] = $p;
            $previous = $p;
        }
        return $out;
    }

    /** Page size from settings, kept within sane bounds. */
    public static function pageSizeFromSettings(): int
    {
        return min(100, max(5, (int) setting('page_size', '25')));
    }
}
