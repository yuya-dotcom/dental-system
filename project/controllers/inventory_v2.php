<?php
// =============================================================
//  controllers/inventory_v2.php — shared inventory rules (enhancement)
//
//  Every stock change goes through inv_move(). After the migration
//  database/proposed/2026-11-18_inventory_enhancement.sql it calls the
//  database function inv_apply_movement(), which in ONE transaction locks
//  the item, checks the rules, updates stock + status + batches and writes
//  the movement (type, before/after, user, reference, batch). Before the
//  migration it falls back to the previous behaviour (compare-and-swap
//  update + a plain movement row), so nothing breaks while the SQL waits.
//
//  Floor rule: normal use (treatments, Stock Out, transfers) must leave at
//  least STOCK_FLOOR unit; write-offs (adjustment / damaged / expired /
//  wastage) may take an item to 0.
// =============================================================
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/stock_helper.php';
require_once __DIR__ . '/staff_notifications.php';   // staffn_stock_touched()

const INV_NEAR_EXPIRY_DAYS = 30;   // a batch expiring within this many days is "expiring soon"

/** Movement types: key => [label, direction (+ / - / ±), may reach 0, can be logged by hand] */
const INV_TYPES = [
    'stock_in'     => ['Stock In',          '+', false, true],
    'stock_out'    => ['Stock Out',         '-', false, true],
    'adjustment'   => ['Stock Adjustment',  '±', true,  true],
    'damaged'      => ['Damaged Items',     '-', true,  true],
    'expired'      => ['Expired Items',     '-', true,  true],
    'wastage'      => ['Wastage',           '-', true,  true],
    'usage'        => ['Used in Treatment', '-', false, false],
    'reversal'     => ['Reversal',          '±', false, false],
    'transfer_out' => ['Transfer Out',      '-', false, false],
    'transfer_in'  => ['Transfer In',       '+', false, false],
    'purchase'     => ['Purchase Delivery', '+', false, false],
];

const INV_UNITS = ['pcs', 'boxes', 'packs', 'bottles', 'vials', 'cartridges', 'tubes', 'rolls', 'pairs', 'sets', 'liters', 'ml', 'grams', 'kits'];

function inv_type_label(?string $t): string { return INV_TYPES[$t ?? ''][0] ?? ($t ? ucwords(str_replace('_', ' ', $t)) : '—'); }

/** Badge classes for a movement type. */
function inv_type_badge(?string $t): string
{
    return match ($t) {
        'stock_in', 'transfer_in', 'purchase' => 'bg-soft-success text-success',
        'stock_out', 'usage', 'transfer_out'  => 'bg-soft-primary text-primary',
        'adjustment', 'reversal'              => 'bg-soft-info text-info',
        'damaged', 'expired', 'wastage'       => 'bg-soft-danger text-danger',
        default                               => 'bg-soft-secondary text-secondary',
    };
}

/** The signed-in user as [id, name] — never taken from the request. */
function inv_actor(): array
{
    return [
        isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
        trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? '')) ?: 'System',
    ];
}

/** A client-made idempotency key, or null when missing / malformed. */
function inv_request_key($k, string $prefix = 'ui'): ?string
{
    $k = is_string($k) ? trim($k) : '';
    return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $k) ? $prefix . ':' . $k : null;
}

/** 'Y-m-d' or null. */
function inv_date($v): ?string
{
    $v = is_string($v) ? trim($v) : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
    [$y, $m, $d] = array_map('intval', explode('-', $v));
    return checkdate($m, $d, $y) ? $v : null;
}

/**
 * True once the migration has been applied (cached in a temp file:
 * "ready" for a day, "not yet" for one minute).
 */
function inv_v2_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'es_inv_v2_' . md5(SUPABASE_URL) . '.flag';
    $c = @file_get_contents($file);
    if ($c !== false && ($p = explode('|', $c)) && count($p) === 2) {
        $ttl = $p[0] === '1' ? 86400 : 60;
        if (time() - (int)$p[1] < $ttl) return $ready = ($p[0] === '1');
    }
    $r = supabase_request('inventory_items', 'GET', [], 'select=master_id&limit=1');
    $ready = ($r['status'] ?? 0) === 200;
    @file_put_contents($file, ($ready ? '1' : '0') . '|' . time());
    return $ready;
}

/** Forget the cached readiness (after the function turns out to be missing). */
function inv_v2_reset(): void
{
    @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'es_inv_v2_' . md5(SUPABASE_URL) . '.flag');
}

/**
 * Display name of a unit. The stored value stays as it is ('pcs' is the database
 * default and the form value); only what people read changes: pcs → piece / pieces.
 */
function inv_unit_label(?string $unit, $n = null): string
{
    $u = trim((string)$unit);
    if (strtolower($u) === 'pcs') return ($n !== null && (int)$n === 1) ? 'piece' : 'pieces';
    return $u;
}

/** Same rule inside a sentence (messages from the database stock functions). */
function inv_unit_text(string $s): string
{
    return preg_replace(['/\b1 pcs\b/', '/\bpcs\b/'], ['1 piece', 'pieces'], $s);
}

/** Calls a database function. Returns the decoded result or ['ok' => false, 'message' => …]. */
function inv_rpc(string $fn, array $args): array
{
    $r = supabase_request('rpc/' . $fn, 'POST', $args);
    if (($r['status'] ?? 0) >= 200 && ($r['status'] ?? 0) < 300 && is_array($r['data'])) {
        // e.g. '"Gloves" must keep at least 1 pcs in stock…' → '… 1 piece …'
        if (isset($r['data']['message']) && is_string($r['data']['message'])) $r['data']['message'] = inv_unit_text($r['data']['message']);
        return $r['data'];
    }
    if (($r['data']['code'] ?? '') === 'PGRST202') inv_v2_reset();
    $msg = $r['error'] ?? 'The database did not answer. Please try again.';
    return ['ok' => false, 'message' => $msg, 'missing' => ($r['data']['code'] ?? '') === 'PGRST202'];
}

/**
 * One stock change + its movement record.
 *  $opts: reason, reference, batch_id (decrease from that batch),
 *         batches ([{lot_number, expiry_date, manufactured_date, quantity, source}] for increases),
 *         request_key, allow_zero (default: from the type), date (Y-m-d)
 * Returns ['ok', 'message', 'before', 'after', 'movement_id', 'duplicate'].
 */
function inv_move(int $itemId, int $change, string $type, array $opts = []): array
{
    if (!isset(INV_TYPES[$type])) return ['ok' => false, 'message' => 'Unknown movement type.'];
    if ($change === 0)            return ['ok' => false, 'message' => 'Quantity must not be zero.'];
    [$uid, $uname] = inv_actor();
    $allowZero = $opts['allow_zero'] ?? INV_TYPES[$type][2];
    $reason    = trim((string)($opts['reason'] ?? '')) ?: INV_TYPES[$type][0];

    if (inv_v2_ready()) {
        $res = inv_rpc('inv_apply_movement', [
            'p_item_id'       => $itemId,
            'p_change'        => $change,
            'p_type'          => $type,
            'p_reason'        => $reason,
            'p_user_id'       => $uid,
            'p_user_name'     => $opts['performed_by'] ?? $uname,
            'p_reference'     => $opts['reference'] ?? null,
            'p_batch_id'      => !empty($opts['batch_id']) ? (int)$opts['batch_id'] : null,
            'p_batches'       => $opts['batches'] ?? null,
            'p_request_key'   => $opts['request_key'] ?? null,
            'p_allow_zero'    => (bool)$allowZero,
            'p_movement_date' => $opts['date'] ?? null,
        ]);
        if (empty($res['missing'])) {
            if (!empty($res['ok']) && empty($res['duplicate'])) staffn_stock_touched($itemId);   // staff bell: low / out of stock
            return [
                'ok'          => !empty($res['ok']),
                'message'     => (string)($res['message'] ?? ''),
                'before'      => isset($res['before']) ? (int)$res['before'] : null,
                'after'       => isset($res['after']) ? (int)$res['after'] : null,
                'movement_id' => $res['movement_id'] ?? null,
                'duplicate'   => !empty($res['duplicate']),
                'batches'     => $res['batches'] ?? [],
            ];
        }
        // function missing after all → old behaviour below
    }

    // ── Before the migration: previous behaviour ─────────────
    $row = stock_rows([$itemId])[$itemId] ?? null;
    if (!$row) return ['ok' => false, 'message' => 'Item not found.'];
    if ($allowZero && $change < 0) {
        // floor-free write-off with compare-and-swap
        for ($i = 0; $i < 3; $i++) {
            $row = stock_rows([$itemId])[$itemId] ?? null;
            $cur = (int)($row['stock_quantity'] ?? 0);
            if ($cur + $change < 0) return ['ok' => false, 'message' => sprintf('"%s" has only %d in stock.', $row['item_name'] ?? 'Item', $cur)];
            if (stock_cas($row, $cur + $change)) { $applied = ['ok' => true, 'before' => $cur, 'after' => $cur + $change]; break; }
            usleep(80000);
        }
        $applied = $applied ?? ['ok' => false, 'message' => 'Stock changed while saving. Please try again.'];
    } else {
        $applied = stock_apply($itemId, $change);
    }
    if (!$applied['ok']) return ['ok' => false, 'message' => $applied['message'] ?? 'Could not update stock.'];
    $ins = supabase_request('inventory_movements', 'POST', [
        'item_id' => $itemId, 'branch_id' => (int)$row['branch_id'] ?: null, 'quantity_change' => $change,
        'reason' => $reason, 'performed_by' => $opts['performed_by'] ?? $uname,
        'movement_date' => $opts['date'] ?? date('Y-m-d'), 'created_at' => date('c'),
    ]);
    if ($ins['error']) {
        stock_apply($itemId, -$change, 1);   // keep stock and history in step
        return ['ok' => false, 'message' => 'Movement could not be logged: ' . $ins['error']];
    }
    staffn_stock_touched($itemId);   // staff bell: low / out of stock (after the response)
    return ['ok' => true, 'message' => '', 'before' => $applied['before'], 'after' => $applied['after'],
            'movement_id' => $ins['data'][0]['movement_id'] ?? null, 'duplicate' => false, 'batches' => []];
}

/** Next ITEM-#### code (the format used by the existing rows). */
function inv_next_item_code(): string
{
    $r = supabase_request('inventory', 'GET', [], 'select=item_code&order=item_id.desc&limit=200');
    $max = 0;
    foreach (($r['data'] ?? []) as $row) {
        $n = (int)preg_replace('/\D/', '', (string)($row['item_code'] ?? ''));
        if ($n > $max) $max = $n;
    }
    return 'ITEM-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

/** Expiry state of a batch: 'expired' | 'soon' | 'ok' | 'none'. */
function inv_expiry_state(?string $date): string
{
    if (!$date) return 'none';
    $today = date('Y-m-d');
    if ($date < $today) return 'expired';
    return $date <= date('Y-m-d', strtotime('+' . INV_NEAR_EXPIRY_DAYS . ' days')) ? 'soon' : 'ok';
}

/**
 * Expiry summary per item for the given balance rows:
 * item_id => ['expired' => units, 'soon' => units, 'next' => earliest live expiry date].
 */
function inv_expiry_by_item(array $itemIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
    if (!$ids || !inv_v2_ready()) return [];
    $limit = date('Y-m-d', strtotime('+' . INV_NEAR_EXPIRY_DAYS . ' days'));
    $r = supabase_request('inventory_batches', 'GET', [],
        'select=item_id,expiry_date,quantity_remaining&quantity_remaining=gt.0&expiry_date=not.is.null'
        . '&item_id=in.(' . implode(',', $ids) . ')&order=expiry_date.asc');
    $out = [];
    foreach (($r['data'] ?? []) as $b) {
        $id = (int)$b['item_id'];
        $out[$id] ??= ['expired' => 0, 'soon' => 0, 'next' => null];
        $st = inv_expiry_state($b['expiry_date']);
        if ($st === 'expired') $out[$id]['expired'] += (int)$b['quantity_remaining'];
        elseif ($st === 'soon') $out[$id]['soon'] += (int)$b['quantity_remaining'];
        if ($st !== 'expired' && !$out[$id]['next']) $out[$id]['next'] = $b['expiry_date'];
    }
    return $out;
}

/** Item ids (in scope) that have expired / expiring-soon stock — for the Records filter. */
function inv_items_with_expiry(string $which, int $branchId): array
{
    if (!inv_v2_ready()) return [];
    $today = date('Y-m-d');
    $q = 'select=item_id&quantity_remaining=gt.0';
    $q .= $which === 'expired'
        ? '&expiry_date=lt.' . $today
        : '&expiry_date=gte.' . $today . '&expiry_date=lte.' . date('Y-m-d', strtotime('+' . INV_NEAR_EXPIRY_DAYS . ' days'));
    if ($branchId !== 0) $q .= '&branch_id=eq.' . $branchId;
    $r = supabase_request('inventory_batches', 'GET', [], $q . '&limit=1000');
    return array_values(array_unique(array_map(fn($x) => (int)$x['item_id'], $r['data'] ?? [])));
}

/** Peso amount. */
function inv_peso($n): string { return '₱' . number_format((float)$n, 2); }

/**
 * Branch name by id, in branch-name order. Built from getAllBranches(), which
 * is remembered for the request and comes from the page's parallel prefetch
 * when there is one — so this never costs a round trip of its own.
 */
function inv_branch_names(): array
{
    require_once __DIR__ . '/branch_controller.php';
    $m = [];
    foreach (getAllBranches() as $b) $m[(int)$b['branch_id']] = (string)$b['branch_name'];
    return $m;
}

/**
 * ONE read of every live batch with an expiry date in scope (0 = all branches)
 * — for admin_prefetch(). inv_expiry_digest() turns it into the three things
 * the Records page needs, instead of three separate round trips.
 */
function inv_batch_scope_spec(int $branchId): array
{
    return admin_q('inventory_batches', 'select=item_id,expiry_date,quantity_remaining&quantity_remaining=gt.0&expiry_date=not.is.null'
        . ($branchId !== 0 ? '&branch_id=eq.' . $branchId : '') . '&order=expiry_date.asc,batch_id.asc&limit=10000');
}

/**
 * From the inv_batch_scope_spec() rows:
 *   soon     item ids with stock expiring within INV_NEAR_EXPIRY_DAYS   (= inv_items_with_expiry('soon'))
 *   expired  item ids holding expired stock                            (= inv_items_with_expiry('expired'))
 *   by_item  item_id => ['expired' => units, 'soon' => units, 'next' => date] (= inv_expiry_by_item())
 */
function inv_expiry_digest(array $rows): array
{
    $soon = $expired = $by = [];
    foreach ($rows as $b) {
        $id = (int)$b['item_id'];
        $st = inv_expiry_state($b['expiry_date']);
        $by[$id] ??= ['expired' => 0, 'soon' => 0, 'next' => null];
        if ($st === 'expired') { $by[$id]['expired'] += (int)$b['quantity_remaining']; $expired[$id] = true; }
        elseif ($st === 'soon') { $by[$id]['soon'] += (int)$b['quantity_remaining']; $soon[$id] = true; }
        if ($st !== 'expired' && !$by[$id]['next']) $by[$id]['next'] = $b['expiry_date'];
    }
    return ['soon' => array_keys($soon), 'expired' => array_keys($expired), 'by_item' => $by];
}

/** Message shown on new pages while the migration has not been run. */
function inv_v2_notice(): string
{
    return '<div class="inv-notice" role="status"><span class="inv-notice-icon"><i class="feather-alert-triangle"></i></span><div>'
        . '<strong>One-time database update needed</strong>'
        . '<span>Some inventory features (batches, transfers, procurement) need <code>database/proposed/2026-11-18_inventory_enhancement.sql</code>. '
        . 'Ask the system administrator to run it in Supabase.</span></div></div>';
}

/** Stock status pill (.inv-flow, es-inventory.css): In Stock / Low Stock / Out of Stock / Archived. */
function inv_stock_pill(?string $status, ?string $archivedAt = null): string
{
    if ($archivedAt) return '<span class="inv-flow f-archived" title="Archived ' . htmlspecialchars(date('M j, Y', strtotime($archivedAt))) . '">Archived</span>';
    $st = strtolower((string)$status);
    $label = ['in_stock' => 'In Stock', 'low_stock' => 'Low Stock', 'out_of_stock' => 'Out of Stock'][$st] ?? ucwords(str_replace('_', ' ', $st));
    return '<span class="inv-flow f-' . htmlspecialchars($st) . '">' . htmlspecialchars($label) . '</span>';
}

/**
 * Empty-state row for the Inventory tables (.rec-empty look from es-records.css):
 * an icon, a title and one line of text — no buttons inside the table.
 * Filters are cleared with the toolbar's Reset or the footer's "Clear filters" link;
 * create buttons (Add Item, Add Supplier, …) live in the page header.
 * $resetUrl is kept so existing calls stay valid.
 */
function inv_empty_row(int $cols, bool $filtered, string $title, string $text, string $icon, string $resetUrl = ''): string
{
    $e = fn($s) => htmlspecialchars($s, ENT_QUOTES);
    if ($filtered) {
        $title = 'No records match your filters';
        $text  = 'Try a different search, or use Reset to see everything.';
    }
    return '<tr><td colspan="' . $cols . '" class="p-0"><div class="rec-empty">'
         . '<span class="rec-empty-icon"><i class="' . $e($filtered ? 'feather-search' : $icon) . '"></i></span>'
         . '<p class="rec-empty-title">' . $e($title) . '</p><p class="rec-empty-text mb-0">' . $e($text) . '</p>'
         . '</div></td></tr>';
}
