<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ErrorPages;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Paginator;
use App\Repositories\AuditLogRepository;
use App\Services\AuditCriteria;

/**
 * Read-only audit trail (audit.view). There is deliberately no way to edit or delete an entry from the application.
 */
final class AuditController extends Controller
{
    private AuditLogRepository $logs;

    public function __construct()
    {
        $this->logs = new AuditLogRepository();
    }

    public function index(Request $request): Response
    {
        $criteria = AuditCriteria::fromRequest($request);
        $total = $this->logs->count($criteria);
        $paginator = new Paginator($total, AuditCriteria::PER_PAGE, $criteria->page);

        return $this->view('audit/index', [
            'title' => 'Audit Log',
            'entries' => $total === 0 ? [] : $this->logs->search($criteria, AuditCriteria::PER_PAGE, $paginator->offset()),
            'criteria' => $criteria,
            'paginator' => $paginator,
            'actions' => $this->logs->distinct('action'),
            'entities' => $this->logs->distinct('entity'),
            'actors' => $this->logs->actors(),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $entry = $this->logs->find($id);
        if ($entry === null) {
            return ErrorPages::response(404);
        }

        return $this->view('audit/show', [
            'title' => 'Audit entry #' . $id,
            'entry' => $entry,
            'changes' => self::changes($entry['old_values'], $entry['new_values']),
        ]);
    }

    /**
     * Field-by-field before/after rows from the two stored JSON documents.
     *
     * @return array<int,array{field:string,old:?string,new:?string}>
     */
    public static function changes(?string $oldJson, ?string $newJson): array
    {
        $old = $oldJson === null ? [] : (json_decode($oldJson, true) ?: []);
        $new = $newJson === null ? [] : (json_decode($newJson, true) ?: []);
        if (!is_array($old) || !is_array($new)) {
            return [];
        }

        $text = static fn (mixed $v): string => is_scalar($v) || $v === null
            ? (string) ($v === null ? '' : (is_bool($v) ? ($v ? 'yes' : 'no') : $v))
            : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $rows = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
            $rows[] = [
                'field' => (string) $field,
                'old' => array_key_exists($field, $old) ? $text($old[$field]) : null,
                'new' => array_key_exists($field, $new) ? $text($new[$field]) : null,
            ];
        }
        return $rows;
    }
}
