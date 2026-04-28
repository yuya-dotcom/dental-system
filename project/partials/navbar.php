<?php
// =============================================================
//  partials/navbar.php — FIXED
//  Change: notification list area now has max-height + overflow-y
//  so it scrolls cleanly when there are many low-stock items.
// =============================================================

require_once __DIR__ . '/../controllers/secure_controller.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
$currentUser = getCurrentUser();

// ── Fetch low/out-of-stock inventory items ────────────────────
$notifBranchFilter = '';
if (!isOwner()) {
    $sessionBranch = getSessionBranch();
    if ($sessionBranch) {
        $notifBranchFilter = '&branch_id=eq.' . (int)$sessionBranch;
    }
}

$lowStockRes   = supabase_request('inventory', 'GET', [],
    'select=item_id,item_code,item_name,stock_quantity,min_stock,status,branches(branch_name)'
    . '&status=in.(low_stock,out_of_stock)'
    . $notifBranchFilter
    . '&order=stock_quantity.asc&limit=50'   // fetch up to 50 — list scrolls
);
$lowStockItems = is_array($lowStockRes['data']) ? $lowStockRes['data'] : [];
$notifCount    = count($lowStockItems);
?>
    <header class="nxl-header">
        <div class="header-wrapper">

            <!-- ── Header Left ──────────────────────────────── -->
            <div class="header-left d-flex align-items-center gap-4">
                <a href="javascript:void(0);" class="nxl-head-mobile-toggler" id="mobile-collapse">
                    <div class="hamburger hamburger--arrowturn">
                        <div class="hamburger-box">
                            <div class="hamburger-inner"></div>
                        </div>
                    </div>
                </a>
                <div class="nxl-navigation-toggle">
                    <a href="javascript:void(0);" id="menu-mini-button"><i class="feather-align-left"></i></a>
                    <a href="javascript:void(0);" id="menu-expend-button" style="display:none;"><i class="feather-arrow-right"></i></a>
                </div>
                <div class="nxl-lavel-mega-menu-toggle d-flex d-lg-none">
                    <a href="javascript:void(0);" id="nxl-lavel-mega-menu-open"><i class="feather-align-left"></i></a>
                </div>
            </div>

            <!-- ── Header Right ─────────────────────────────── -->
            <div class="header-right ms-auto">
                <div class="d-flex align-items-center">

                    <!-- Search -->
                    <div class="dropdown nxl-h-item nxl-header-search">
                        <a href="javascript:void(0);" class="nxl-head-link me-0"
                           data-bs-toggle="dropdown" data-bs-auto-close="outside">
                            <i class="feather-search"></i>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end nxl-h-dropdown nxl-search-dropdown">
                            <div class="input-group search-form">
                                <span class="input-group-text"><i class="feather-search fs-6 text-muted"></i></span>
                                <input type="text" class="form-control search-input-field" placeholder="Search....">
                                <span class="input-group-text"><button type="button" class="btn-close"></button></span>
                            </div>
                        </div>
                    </div>

                    <!-- Full screen -->
                    <div class="nxl-h-item d-none d-sm-flex">
                        <div class="full-screen-switcher">
                            <a href="javascript:void(0);" class="nxl-head-link me-0"
                               onclick="$('body').fullScreenHelper('toggle');">
                                <i class="feather-maximize maximize"></i>
                                <i class="feather-minimize minimize"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Dark / Light -->
                    <div class="nxl-h-item dark-light-theme">
                        <a href="javascript:void(0);" class="nxl-head-link me-0 dark-button"><i class="feather-moon"></i></a>
                        <a href="javascript:void(0);" class="nxl-head-link me-0 light-button" style="display:none;"><i class="feather-sun"></i></a>
                    </div>

                    <!-- ── NOTIFICATIONS ────────────────────── -->
                    <div class="dropdown nxl-h-item">
                        <a class="nxl-head-link me-3" data-bs-toggle="dropdown" href="#" role="button"
                           data-bs-auto-close="outside">
                            <i class="feather-bell"></i>
                            <?php if ($notifCount > 0): ?>
                            <span class="badge bg-danger nxl-h-badge">
                                <?= $notifCount > 9 ? '9+' : $notifCount ?>
                            </span>
                            <?php endif; ?>
                        </a>

                        <!--
                            SCROLLABLE NOTIFICATION DROPDOWN
                            The dropdown itself keeps its normal width/shadow.
                            Only the inner scrollable list area (.notif-scroll-area)
                            gets max-height + overflow-y:auto so the header and
                            footer always stay visible.
                        -->
                        <div class="dropdown-menu dropdown-menu-end nxl-h-dropdown nxl-notifications-menu"
                             style="min-width:340px; max-width:380px;">

                            <!-- Fixed header row -->
                            <div class="d-flex justify-content-between align-items-center notifications-head px-3 py-2 border-bottom">
                                <h6 class="fw-bold text-dark mb-0">
                                    <i class="feather-bell me-1 text-primary"></i> Notifications
                                    <?php if ($notifCount > 0): ?>
                                    <span class="badge bg-danger ms-1"><?= $notifCount ?></span>
                                    <?php endif; ?>
                                </h6>
                                <?php if ($notifCount > 0): ?>
                                <a href="inventory-records.php" class="fs-11 text-primary ms-auto">
                                    View All
                                </a>
                                <?php endif; ?>
                            </div>

                            <?php if ($notifCount === 0): ?>
                            <!-- All clear state -->
                            <div class="px-4 py-4 text-center text-muted small">
                                <i class="feather-check-circle d-block mb-1 text-success" style="font-size:1.6rem;"></i>
                                All inventory levels are healthy.
                            </div>

                            <?php else: ?>

                            <!-- Section label (fixed, above scroll) -->
                            <div class="px-3 pt-2 pb-1">
                                <p class="fs-11 fw-semibold text-uppercase text-muted mb-0">
                                    <i class="feather-alert-triangle me-1 text-warning"></i>
                                    Low / Out-of-Stock Items
                                </p>
                            </div>

                            <!--
                                SCROLLABLE LIST AREA
                                max-height: 300px keeps the dropdown a reasonable size
                                even with 20+ notifications.
                                overflow-y: auto adds the scrollbar only when needed.
                            -->
                            <div class="notif-scroll-area"
                                 style="max-height:300px; overflow-y:auto; overflow-x:hidden;">

                                <?php foreach ($lowStockItems as $item):
                                    $isOut      = ($item['status'] ?? '') === 'out_of_stock';
                                    $iconClass  = $isOut ? 'feather-alert-octagon' : 'feather-alert-triangle';
                                    $colorClass = $isOut ? 'text-danger' : 'text-warning';
                                    $bgClass    = $isOut ? 'bg-soft-danger'  : 'bg-soft-warning';
                                    $badgeClass = $isOut ? 'bg-soft-danger text-danger' : 'bg-soft-warning text-warning';
                                    $label      = $isOut ? 'Out of Stock' : 'Low Stock';
                                    $branch     = htmlspecialchars($item['branches']['branch_name'] ?? '');
                                ?>
                                <a href="inventory-records.php"
                                   class="notifications-item d-flex align-items-start px-3 py-2 border-bottom text-decoration-none">
                                    <div class="me-3 mt-1 flex-shrink-0">
                                        <span class="avatar-text avatar-sm <?= $bgClass ?>">
                                            <i class="<?= $iconClass ?> <?= $colorClass ?>"></i>
                                        </span>
                                    </div>
                                    <div class="flex-grow-1 min-width-0">
                                        <div class="fw-semibold text-dark text-truncate">
                                            <?= htmlspecialchars($item['item_name'] ?? '—') ?>
                                        </div>
                                        <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
                                            <span class="badge <?= $badgeClass ?> small"><?= $label ?></span>
                                            <span class="text-muted fs-11">
                                                Stock: <strong><?= (int)($item['stock_quantity'] ?? 0) ?></strong>
                                                / Min: <?= (int)($item['min_stock'] ?? 0) ?>
                                            </span>
                                            <?php if ($branch): ?>
                                            <span class="text-muted fs-11">
                                                <i class="feather-map-pin" style="font-size:10px;"></i>
                                                <?= $branch ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </a>
                                <?php endforeach; ?>

                            </div><!-- /notif-scroll-area -->

                            <?php endif; ?>

                            <!-- Fixed footer -->
                            <div class="text-center py-2 border-top">
                                <a href="inventory-records.php" class="fs-13 fw-semibold text-dark">
                                    <i class="feather-package me-1"></i>Go to Inventory
                                </a>
                            </div>

                        </div><!-- /dropdown-menu -->
                    </div>
                    <!-- ── /NOTIFICATIONS ───────────────────── -->

                    <!-- User Profile -->
                    <div class="dropdown nxl-h-item">
                        <a class="nxl-head-link me-0" data-bs-toggle="dropdown" href="#" role="button">
                            <div class="d-flex align-items-center gap-2">
                                <div class="avatar-text avatar-sm bg-primary text-white">
                                    <?= strtoupper(substr($currentUser['full_name'] ?? 'U', 0, 1)) ?>
                                </div>
                                <div class="d-none d-md-block lh-1">
                                    <div class="fs-13 fw-semibold text-dark">
                                        <?= htmlspecialchars($currentUser['full_name'] ?? '') ?>
                                    </div>
                                    <div class="fs-11 text-muted"><?= ucfirst($currentUser['role'] ?? '') ?></div>
                                </div>
                                <i class="feather-chevron-down fs-12 text-muted ms-1"></i>
                            </div>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end nxl-h-dropdown" style="min-width:200px;">
                            <div class="px-3 py-2 border-bottom">
                                <div class="fw-semibold text-dark">
                                    <?= htmlspecialchars($currentUser['full_name'] ?? '') ?>
                                </div>
                                <div class="fs-12 text-muted">
                                    <?= htmlspecialchars($currentUser['email'] ?? '') ?>
                                </div>
                                <span class="badge bg-soft-primary text-primary mt-1">
                                    <?= ucfirst($currentUser['role'] ?? '') ?>
                                </span>
                            </div>
                            <div class="dropdown-divider"></div>
                            <a href="logout.php" class="dropdown-item text-danger">
                                <i class="feather-log-out me-2"></i>Sign Out
                            </a>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </header>