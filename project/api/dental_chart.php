<?php
// =============================================================
//  api/dental_chart.php
//
//  GET  ?patient_id=X              — full chart for patient
//  POST action=upsert_tooth        — set whole-tooth condition
//  POST action=upsert_surface      — set per-surface condition
//  POST action=get_history         — fetch audit history
// =============================================================

session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';

header('Content-Type: application/json');
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: fetch full chart for a patient ───────────────────────
if ($method === 'GET') {
    $patientId = (int)($_GET['patient_id'] ?? 0);
    if (!$patientId) {
        echo json_encode(['success' => false, 'message' => 'patient_id required.']);
        exit;
    }

    // Fetch base dental_chart rows
    $chartRes = supabase_request(
        'dental_chart', 'GET', [],
        'patient_id=eq.' . $patientId .
        '&select=chart_id,tooth_number,condition,notes,updated_at,updated_by' .
        '&order=tooth_number.asc'
    );

    $teeth = $chartRes['data'] ?? [];

    // For each tooth, fetch its surfaces
    $result = [];
    foreach ($teeth as $tooth) {
        $chartId = $tooth['chart_id'];
        $surfRes = supabase_request(
            'dental_chart_surfaces', 'GET', [],
            'chart_id=eq.' . $chartId .
            '&select=surface,condition,notes'
        );
        $surfaces = [];
        foreach (($surfRes['data'] ?? []) as $s) {
            $surfaces[$s['surface']] = [
                'condition' => $s['condition'],
                'notes'     => $s['notes'],
            ];
        }
        $result[] = [
            'chart_id'    => $chartId,
            'tooth_number'=> $tooth['tooth_number'],
            'condition'   => $tooth['condition'],
            'notes'       => $tooth['notes'],
            'updated_at'  => $tooth['updated_at'],
            'surfaces'    => $surfaces,
        ];
    }

    echo json_encode(['success' => true, 'chart' => $result]);
    exit;
}

// ── POST ─────────────────────────────────────────────────────
if ($method === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $action    = $body['action'] ?? '';
    $patientId = (int)($body['patient_id'] ?? 0);
    $toothNum  = trim($body['tooth_number'] ?? '');
    $userId    = (int)($_SESSION['user_id'] ?? 0);
    $userName  = $_SESSION['full_name'] ?? 'Unknown';
    $source    = $body['source'] ?? 'consultation'; // consultation | treatment_record | correction

    if (!$patientId || !$toothNum) {
        echo json_encode(['success' => false, 'message' => 'patient_id and tooth_number are required.']);
        exit;
    }

    // ── Helper: get or create dental_chart row ─────────────────
    function getOrCreateChartRow($patientId, $toothNum, $userId) {
        $existing = supabase_request(
            'dental_chart', 'GET', [],
            'patient_id=eq.' . $patientId .
            '&tooth_number=eq.' . urlencode($toothNum) .
            '&select=chart_id,condition,notes&limit=1'
        );

        if (!empty($existing['data'][0])) {
            return $existing['data'][0];
        }

        // Create new row
        $payload = [
            'patient_id'  => $patientId,
            'tooth_number'=> $toothNum,
            'condition'   => 'healthy',
            'recorded_by' => $userId ?: null,
            'updated_by'  => $userId ?: null,
            'recorded_at' => date('c'),
            'updated_at'  => date('c'),
        ];
        $res = supabase_request('dental_chart', 'POST', $payload, 'select=chart_id,condition,notes');
        return $res['data'][0] ?? null;
    }

    // ── action: upsert_tooth ───────────────────────────────────
    if ($action === 'upsert_tooth') {
        $newCondition = trim($body['condition'] ?? '');
        $notes        = trim($body['notes'] ?? '');

        $validConditions = ['healthy','filled','decay','impacted','missing','crown','impacted_ne'];
        if (!in_array($newCondition, $validConditions)) {
            echo json_encode(['success' => false, 'message' => 'Invalid condition.']);
            exit;
        }

        $row = getOrCreateChartRow($patientId, $toothNum, $userId);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Failed to get or create chart row.']);
            exit;
        }

        $chartId      = $row['chart_id'];
        $oldCondition = $row['condition'];

        // Update dental_chart row
        $updatePayload = [
            'condition'  => $newCondition,
            'notes'      => $notes ?: null,
            'updated_by' => $userId ?: null,
            'updated_at' => date('c'),
        ];
        supabase_request('dental_chart', 'PATCH', $updatePayload, 'chart_id=eq.' . $chartId);

        // Log history
        $histPayload = [
            'patient_id'    => $patientId,
            'tooth_number'  => $toothNum,
            'surface'       => null,
            'old_condition' => $oldCondition,
            'new_condition' => $newCondition,
            'notes'         => $notes ?: null,
            'changed_by'    => $userId ?: null,
            'changed_at'    => date('c'),
            'source'        => $source,
        ];
        supabase_request('dental_chart_history', 'POST', $histPayload);

        echo json_encode(['success' => true, 'chart_id' => $chartId, 'message' => 'Tooth condition updated.']);
        exit;
    }

    // ── action: upsert_surface ─────────────────────────────────
    if ($action === 'upsert_surface') {
        $surface      = strtoupper(trim($body['surface'] ?? ''));
        $newCondition = trim($body['condition'] ?? '');
        $notes        = trim($body['notes'] ?? '');

        $validSurfaces   = ['B','M','O','L','D'];
        $validConditions = ['healthy','filled','decay','impacted','missing','crown','impacted_ne'];

        if (!in_array($surface, $validSurfaces)) {
            echo json_encode(['success' => false, 'message' => 'Invalid surface. Must be B, M, O, L, or D.']);
            exit;
        }
        if (!in_array($newCondition, $validConditions)) {
            echo json_encode(['success' => false, 'message' => 'Invalid condition.']);
            exit;
        }

        $row = getOrCreateChartRow($patientId, $toothNum, $userId);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Failed to get or create chart row.']);
            exit;
        }

        $chartId = $row['chart_id'];

        // Check existing surface row
        $existingSurf = supabase_request(
            'dental_chart_surfaces', 'GET', [],
            'chart_id=eq.' . $chartId .
            '&surface=eq.' . $surface .
            '&select=surface_id,condition&limit=1'
        );

        $oldCondition = null;

        if (!empty($existingSurf['data'][0])) {
            $surfaceId    = $existingSurf['data'][0]['surface_id'];
            $oldCondition = $existingSurf['data'][0]['condition'];
            // Update
            supabase_request('dental_chart_surfaces', 'PATCH', [
                'condition'  => $newCondition,
                'notes'      => $notes ?: null,
                'updated_at' => date('c'),
            ], 'surface_id=eq.' . $surfaceId);
        } else {
            // Insert
            supabase_request('dental_chart_surfaces', 'POST', [
                'chart_id'   => $chartId,
                'surface'    => $surface,
                'condition'  => $newCondition,
                'notes'      => $notes ?: null,
                'updated_at' => date('c'),
            ]);
        }

        // Log history
        supabase_request('dental_chart_history', 'POST', [
            'patient_id'    => $patientId,
            'tooth_number'  => $toothNum,
            'surface'       => $surface,
            'old_condition' => $oldCondition,
            'new_condition' => $newCondition,
            'notes'         => $notes ?: null,
            'changed_by'    => $userId ?: null,
            'changed_at'    => date('c'),
            'source'        => $source,
        ]);

        // Update parent tooth updated_at
        supabase_request('dental_chart', 'PATCH', [
            'updated_by' => $userId ?: null,
            'updated_at' => date('c'),
        ], 'chart_id=eq.' . $chartId);

        echo json_encode(['success' => true, 'chart_id' => $chartId, 'message' => 'Surface condition updated.']);
        exit;
    }

    // ── action: upsert_batch (save all pending changes at once) ─
    if ($action === 'upsert_batch') {
        $changes = $body['changes'] ?? [];
        if (empty($changes)) {
            echo json_encode(['success' => true, 'message' => 'Nothing to save.']);
            exit;
        }

        $saved   = 0;
        $errors  = [];

        foreach ($changes as $change) {
            $tNum      = trim($change['tooth_number'] ?? '');
            $surface   = strtoupper(trim($change['surface'] ?? ''));
            $condition = trim($change['condition'] ?? '');
            $notes     = trim($change['notes'] ?? '');
            $type      = $change['type'] ?? 'tooth'; // 'tooth' | 'surface'

            if (!$tNum || !$condition) continue;

            $validConditions = ['healthy','filled','decay','impacted','missing','crown','impacted_ne'];
            if (!in_array($condition, $validConditions)) continue;

            $row = getOrCreateChartRow($patientId, $tNum, $userId);
            if (!$row) { $errors[] = "Failed for tooth $tNum"; continue; }

            $chartId = $row['chart_id'];

            if ($type === 'tooth' || !$surface) {
                // Whole-tooth update
                $oldCond = $row['condition'];
                supabase_request('dental_chart', 'PATCH', [
                    'condition'  => $condition,
                    'notes'      => $notes ?: null,
                    'updated_by' => $userId ?: null,
                    'updated_at' => date('c'),
                ], 'chart_id=eq.' . $chartId);

                supabase_request('dental_chart_history', 'POST', [
                    'patient_id'    => $patientId,
                    'tooth_number'  => $tNum,
                    'surface'       => null,
                    'old_condition' => $oldCond,
                    'new_condition' => $condition,
                    'notes'         => $notes ?: null,
                    'changed_by'    => $userId ?: null,
                    'changed_at'    => date('c'),
                    'source'        => $source,
                ]);

            } else {
                // Surface update
                $validSurfaces = ['B','M','O','L','D'];
                if (!in_array($surface, $validSurfaces)) continue;

                $existingSurf = supabase_request(
                    'dental_chart_surfaces', 'GET', [],
                    'chart_id=eq.' . $chartId . '&surface=eq.' . $surface . '&select=surface_id,condition&limit=1'
                );

                $oldCond = null;
                if (!empty($existingSurf['data'][0])) {
                    $oldCond = $existingSurf['data'][0]['condition'];
                    supabase_request('dental_chart_surfaces', 'PATCH', [
                        'condition'  => $condition,
                        'notes'      => $notes ?: null,
                        'updated_at' => date('c'),
                    ], 'surface_id=eq.' . $existingSurf['data'][0]['surface_id']);
                } else {
                    supabase_request('dental_chart_surfaces', 'POST', [
                        'chart_id'   => $chartId,
                        'surface'    => $surface,
                        'condition'  => $condition,
                        'notes'      => $notes ?: null,
                        'updated_at' => date('c'),
                    ]);
                }

                supabase_request('dental_chart_history', 'POST', [
                    'patient_id'    => $patientId,
                    'tooth_number'  => $tNum,
                    'surface'       => $surface,
                    'old_condition' => $oldCond,
                    'new_condition' => $condition,
                    'notes'         => $notes ?: null,
                    'changed_by'    => $userId ?: null,
                    'changed_at'    => date('c'),
                    'source'        => $source,
                ]);

                supabase_request('dental_chart', 'PATCH', [
                    'updated_by' => $userId ?: null,
                    'updated_at' => date('c'),
                ], 'chart_id=eq.' . $chartId);
            }

            $saved++;
        }

        echo json_encode([
            'success' => true,
            'saved'   => $saved,
            'errors'  => $errors,
            'message' => "$saved change(s) saved successfully.",
        ]);
        exit;
    }

    // ── action: get_history ────────────────────────────────────
    if ($action === 'get_history') {
        $tNum = trim($body['tooth_number'] ?? '');
        $q    = 'patient_id=eq.' . $patientId .
                '&select=tooth_number,surface,old_condition,new_condition,notes,changed_at,source' .
                '&order=changed_at.desc&limit=30';
        if ($tNum) $q .= '&tooth_number=eq.' . urlencode($tNum);

        $res = supabase_request('dental_chart_history', 'GET', [], $q);
        echo json_encode(['success' => true, 'history' => $res['data'] ?? []]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Method not allowed.']);