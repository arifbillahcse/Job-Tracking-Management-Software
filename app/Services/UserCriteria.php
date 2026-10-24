<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Request;

/**
 * Validated search / filter / sort parameters for the user list. Same approach as JobCriteria:
 * sort columns and directions come from a whitelist, everything else is checked before it reaches SQL.
 */
final class UserCriteria
{
    public const PER_PAGE = 25;
    public const STATUSES = ['active', 'inactive', 'locked'];

    /** @var array<string,string> sort key => SQL column */
    private const SORTS = [
        'name' => 'u.name',
        'email' => 'u.email',
        'role' => 'r.name',
        'last_login' => 'u.last_login_at',
        'created' => 'u.id',
    ];

    private function __construct(
        public readonly string $q,
        public readonly int $roleId,
        public readonly string $status,
        public readonly string $sort,
        public readonly string $dir,
        public readonly int $page
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $q = $request->query('q', '');
        $q = is_string($q) ? mb_substr(trim((string) preg_replace('/\s+/', ' ', $q)), 0, 100) : '';

        $status = $request->queryStr('status', '');
        $sort = $request->queryStr('sort', '');
        $sorted = isset(self::SORTS[$sort]);

        return new self(
            $q,
            max(0, $request->queryInt('role', 0)),
            in_array($status, self::STATUSES, true) ? $status : '',
            $sorted ? $sort : 'name',
            $sorted && strtolower($request->queryStr('dir', 'asc')) === 'desc' ? 'desc' : 'asc',
            max(1, $request->queryInt('page', 1))
        );
    }

    /** ORDER BY with a unique tie-breaker so pages never overlap. */
    public function orderBySql(): string
    {
        return 'ORDER BY ' . self::SORTS[$this->sort] . ' ' . ($this->dir === 'desc' ? 'DESC' : 'ASC') . ', u.id ASC';
    }

    public function hasFilters(): bool
    {
        return $this->q !== '' || $this->roleId > 0 || $this->status !== '';
    }

    /**
     * @return array<string,string|int>
     */
    public function linkParams(): array
    {
        $params = ['q' => $this->q, 'role' => $this->roleId, 'status' => $this->status];
        if ($this->sort !== 'name' || $this->dir !== 'asc') {
            $params['sort'] = $this->sort;
            $params['dir'] = $this->dir;
        }
        return array_filter($params, static fn (string|int $v): bool => $v !== '' && $v !== 0);
    }

    /**
     * @return array<string,string|int>
     */
    public function sortParams(string $key): array
    {
        $params = $this->linkParams();
        $params['sort'] = $key;
        $params['dir'] = ($this->sort === $key && $this->dir === 'asc') ? 'desc' : 'asc';
        return $params;
    }
}
