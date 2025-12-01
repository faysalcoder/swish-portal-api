<?php
namespace App\Controllers;

use App\Models\RaciMatrix;
use App\Models\RaciRole;

class RaciMatricesController extends BaseController
{
    protected RaciMatrix $model;
    protected RaciRole $roleModel;

    public function __construct()
    {
        parent::__construct();
        $this->model = new RaciMatrix();
        $this->roleModel = new RaciRole();
    }

    /**
     * Accept many datetime input shapes and return SQL 'Y-m-d H:i:s' or null
     */
    private function normalizeDateTime($input): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }

        // numeric timestamp handling (seconds or milliseconds)
        if (is_numeric($input)) {
            $num = (float)$input;
            // if looks like ms (greater than ~1e12) convert to seconds
            if ($num > 1000000000000) {
                $num = (int)($num / 1000);
            }
            return date('Y-m-d H:i:s', (int)$num);
        }

        // try DateTime constructor (supports ISO with offsets)
        try {
            $dt = new \DateTime($input);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $ts = strtotime($input);
            if ($ts !== false) {
                return date('Y-m-d H:i:s', $ts);
            }
        }

        return null;
    }

    /**
     * Convert row's date columns to ISO-8601 fields used by frontend
     * Adds start_datetime and deadline_datetime (RFC3339)
     */
    private function formatRowDates($row)
    {
        $dateCols = [
            // database column => api field name for output
            'start_date' => 'start_datetime',
            'deadline'   => 'deadline_datetime',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];

        // object
        if (is_object($row)) {
            foreach ($dateCols as $col => $outKey) {
                if (!empty($row->{$col})) {
                    try {
                        $dt = new \DateTime($row->{$col});
                        // produce RFC3339 (ISO) for client
                        $iso = $dt->format(\DateTime::ATOM);
                        // keep original DB name as well for backward compatibility
                        $row->{$outKey} = $iso;
                    } catch (\Exception $e) {
                        $row->{$outKey} = null;
                    }
                } else {
                    $row->{$outKey} = null;
                }
            }
            return $row;
        }

        // array
        if (is_array($row)) {
            foreach ($dateCols as $col => $outKey) {
                if (!empty($row[$col])) {
                    try {
                        $dt = new \DateTime($row[$col]);
                        $row[$outKey] = $dt->format(\DateTime::ATOM);
                    } catch (\Exception $e) {
                        $row[$outKey] = null;
                    }
                } else {
                    $row[$outKey] = null;
                }
            }
            return $row;
        }

        return $row;
    }

    /**
     * GET /api/v1/raci
     */
    public function index(): void
    {
        $this->requireAuth();
        $wing = $_GET['wing_id'] ?? null;
        $month = $_GET['month'] ?? null; // expects YYYY-MM-01 or similar
        $conds = [];

        if ($wing !== null && $wing !== '') $conds['wing_id'] = (int)$wing;
        if ($month) $conds['month'] = $month;

        $rows = !empty($conds) ? $this->model->where($conds, 1000, 0) : $this->model->all(1000, 0);

        if (is_array($rows)) {
            foreach ($rows as $k => $r) {
                $rows[$k] = $this->formatRowDates($r);
            }
        }

        $this->success($rows);
    }

    /**
     * GET /api/v1/raci/{id}
     */
    public function show($id): void
    {
        $this->requireAuth();
        $row = $this->model->find((int)$id);
        if (!$row) $this->error('Not found', 404);

        $row = $this->formatRowDates($row);

        // attach roles for this raci (helpful for UI)
        try {
            $roles = $this->roleModel->byRaci((int)$id);
            // keep roles as array of objects
            if (is_object($row)) {
                $row->roles = $roles;
            } elseif (is_array($row)) {
                $row['roles'] = $roles;
            }
        } catch (\Exception $e) {
            // don't fail the request if roles loading fails
        }

        $this->success($row);
    }

    /**
     * POST /api/v1/raci
     * Accepts: wing_id, subw_id, title, status, start_datetime OR start_date, deadline_datetime OR deadline, notes, month
     */
    public function store(): void
    {
        $this->requireAuth();
        $data = $this->jsonInput();

        if (empty($data['title'])) $this->error('title required', 422);

        // pick allowed keys (we will map datetime fields below)
        $allowed = [
            'wing_id',
            'subw_id',
            'title',
            'status',
            'deadline',
            'start_date',
            'notes',
            'month'
        ];

        // Normalize: accept start_datetime / deadline_datetime from frontend
        if (isset($data['start_datetime']) && $data['start_datetime'] !== '') {
            $data['start_date'] = $data['start_datetime'];
        }
        if (isset($data['deadline_datetime']) && $data['deadline_datetime'] !== '') {
            $data['deadline'] = $data['deadline_datetime'];
        }

        $payload = array_intersect_key($data, array_flip($allowed));

        // normalize datetimes into DB format 'Y-m-d H:i:s'
        if (isset($payload['start_date'])) {
            $payload['start_date'] = $this->normalizeDateTime($payload['start_date']);
        }
        if (isset($payload['deadline'])) {
            $payload['deadline'] = $this->normalizeDateTime($payload['deadline']);
        }

        // coerce integer FK fields
        if (isset($payload['wing_id'])) $payload['wing_id'] = (int)$payload['wing_id'];
        if (isset($payload['subw_id'])) $payload['subw_id'] = (int)$payload['subw_id'];

        $id = $this->model->create($payload);
        $created = $this->model->find($id);
        $created = $this->formatRowDates($created);

        // If client provided roles in the create payload, insert them
        if (!empty($data['roles']) && is_array($data['roles'])) {
            foreach ($data['roles'] as $r) {
                // allow role objects or simple arrays: { role, user_id }
                $roleChar = null;
                $userId = null;
                if (is_array($r)) {
                    $roleChar = $r['role'] ?? $r['letter'] ?? null;
                    $userId = $r['user_id'] ?? $r['uid'] ?? $r['userId'] ?? null;
                } elseif (is_object($r)) {
                    $roleChar = $r->role ?? $r->letter ?? null;
                    $userId = $r->user_id ?? $r->uid ?? $r->userId ?? null;
                }
                if ($roleChar && $userId) {
                    $rc = strtoupper(substr(trim((string)$roleChar), 0, 1));
                    if (in_array($rc, ['R','A','C','I'])) {
                        try {
                            $this->roleModel->create([
                                'raci_id' => (int)$id,
                                'role' => $rc,
                                'user_id' => (int)$userId
                            ]);
                        } catch (\Exception $e) {
                            // ignore single role failures
                        }
                    }
                }
            }
            // refresh roles list
            try {
                $roles = $this->roleModel->byRaci((int)$id);
                if (is_object($created)) $created->roles = $roles;
                elseif (is_array($created)) $created['roles'] = $roles;
            } catch (\Exception $e) { }
        }

        $this->success($created, 'Created', 201);
    }

    /**
     * PUT /api/v1/raci/{id}
     * Accepts same fields as store()
     * If 'roles' array is present the controller will replace roles for this raci.
     */
    public function update($id): void
    {
        $this->requireAuth();
        $data = $this->jsonInput();

        if (!$id) $this->error('Missing id', 422);

        // Accept frontend names
        if (isset($data['start_datetime']) && $data['start_datetime'] !== '') {
            $data['start_date'] = $data['start_datetime'];
        }
        if (isset($data['deadline_datetime']) && $data['deadline_datetime'] !== '') {
            $data['deadline'] = $data['deadline_datetime'];
        }

        $allowed = [
            'wing_id',
            'subw_id',
            'title',
            'status',
            'deadline',
            'start_date',
            'notes',
            'month'
        ];

        $payload = array_intersect_key($data, array_flip($allowed));

        // normalize datetimes
        if (isset($payload['start_date'])) {
            $payload['start_date'] = $this->normalizeDateTime($payload['start_date']);
        }
        if (isset($payload['deadline'])) {
            $payload['deadline'] = $this->normalizeDateTime($payload['deadline']);
        }

        if (isset($payload['wing_id'])) $payload['wing_id'] = (int)$payload['wing_id'];
        if (isset($payload['subw_id'])) $payload['subw_id'] = (int)$payload['subw_id'];

        $ok = $this->model->update((int)$id, $payload);
        if (!$ok) $this->error('Update failed', 500);

        // If client supplied roles array, replace existing roles for this raci
        if (isset($data['roles']) && is_array($data['roles'])) {
            try {
                // delete existing roles for this raci
                $existing = $this->roleModel->byRaci((int)$id);
                if (is_array($existing)) {
                    foreach ($existing as $er) {
                        if (!empty($er->id)) {
                            $this->roleModel->delete((int)$er->id);
                        }
                    }
                }

                // create new roles
                foreach ($data['roles'] as $r) {
                    $roleChar = null;
                    $userId = null;
                    if (is_array($r)) {
                        $roleChar = $r['role'] ?? $r['letter'] ?? null;
                        $userId = $r['user_id'] ?? $r['uid'] ?? $r['userId'] ?? null;
                    } elseif (is_object($r)) {
                        $roleChar = $r->role ?? $r->letter ?? null;
                        $userId = $r->user_id ?? $r->uid ?? $r->userId ?? null;
                    }
                    if ($roleChar && $userId) {
                        $rc = strtoupper(substr(trim((string)$roleChar), 0, 1));
                        if (in_array($rc, ['R','A','C','I'])) {
                            $this->roleModel->create([
                                'raci_id'  => (int)$id,
                                'role'     => $rc,
                                'user_id'  => (int)$userId
                            ]);
                        }
                    }
                }
            } catch (\Exception $e) {
                // ignore role replace failures; do not break update
            }
        }

        $updated = $this->model->find((int)$id);
        $updated = $this->formatRowDates($updated);

        // attach roles after update
        try {
            $roles = $this->roleModel->byRaci((int)$id);
            if (is_object($updated)) $updated->roles = $roles;
            elseif (is_array($updated)) $updated['roles'] = $roles;
        } catch (\Exception $e) {}

        $this->success($updated, 'Updated');
    }

    /**
     * DELETE /api/v1/raci/{id}
     */
    public function destroy($id): void
    {
        $this->requireAuth();
        $ok = $this->model->delete((int)$id);
        if (!$ok) $this->error('Delete failed', 500);
        $this->success(null, 'Deleted', 204);
    }
}
