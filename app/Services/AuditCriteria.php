<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Request;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Validated filters for the audit log viewer. Action and entity must look like identifiers, dates must be
 * real dates; anything else is dropped before it can reach SQL. Dates are typed in the business time zone
 * and converted to the UTC range the table stores.
 */
final class AuditCriteria
{
    public const PER_PAGE = 50;

    private function __construct(
        public readonly string $action,
        public readonly string $entity,
        public readonly int $userId,
        public readonly string $from,
        public readonly string $to,
        public readonly int $entityId,
        public readonly int $page
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $identifier = static function (mixed $value): string {
            $value = is_string($value) ? trim($value) : '';
            return preg_match('/^[a-z][a-z0-9_]{0,59}$/', $value) === 1 ? $value : '';
        };
        $date = static function (mixed $value): string {
            $value = is_string($value) ? trim($value) : '';
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : '';
        };

        $from = $date($request->query('from', ''));
        $to = $date($request->query('to', ''));
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return new self(
            $identifier($request->query('action', '')),
            $identifier($request->query('entity', '')),
            max(0, (int) $request->query('user', 0)),
            $from,
            $to,
            max(0, (int) $request->query('entity_id', 0)),
            max(1, (int) $request->query('page', 1))
        );
    }

    /** Inclusive start of the first day, as a UTC timestamp, or null. */
    public function fromUtc(): ?string
    {
        return $this->from === '' ? null : $this->bound($this->from, '00:00:00');
    }

    /** Inclusive end of the last day, as a UTC timestamp, or null. */
    public function toUtc(): ?string
    {
        return $this->to === '' ? null : $this->bound($this->to, '23:59:59');
    }

    private function bound(string $day, string $time): string
    {
        try {
            $zone = new DateTimeZone(setting('timezone', 'UTC') ?: 'UTC');
        } catch (Throwable) {
            $zone = new DateTimeZone('UTC');
        }
        return (new DateTimeImmutable($day . ' ' . $time, $zone))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function hasFilters(): bool
    {
        return $this->action !== '' || $this->entity !== '' || $this->userId > 0 || $this->from !== '' || $this->to !== '' || $this->entityId > 0;
    }

    /**
     * @return array<string,string|int>
     */
    public function linkParams(): array
    {
        return array_filter([
            'action' => $this->action, 'entity' => $this->entity, 'user' => $this->userId,
            'entity_id' => $this->entityId, 'from' => $this->from, 'to' => $this->to,
        ], static fn (string|int $v): bool => $v !== '' && $v !== 0);
    }
}
