<?php
// =============================================================
//  partials/navbar.php — staff header: bell (Notification Center) + profile
// =============================================================

require_once __DIR__ . '/../controllers/secure_controller.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/admin_batch.php';
require_once __DIR__ . '/../controllers/branch_controller.php';   // getAllBranches() for transfer / request alerts
$currentUser = getCurrentUser();

// ── Notification Center (bell) ────────────────────────────────
// The bell is filled in the browser from api/staff_notifications.php
// (assets/js/es-notifications.js), so pages no longer query inventory
// for it on every load. Only the category filter is rendered here —
// the same categories the API allows this account to see.
require_once __DIR__ . '/../controllers/staff_notifications.php';
$_snViewer = staffn_viewer();
$_snCats   = $_snViewer ? staffn_category_options($_snViewer) : [];
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
            </div>

            <!-- ── Header Right ─────────────────────────────── -->
            <div class="header-right ms-auto">
                <div class="d-flex align-items-center">

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

                    <!-- ── NOTIFICATIONS (assets/js/es-notifications.js) ── -->
                    <div class="dropdown nxl-h-item" id="esnBell" data-user="<?= (int)($currentUser['user_id'] ?? 0) ?>">
                        <a class="nxl-head-link me-3" id="esnToggle" data-bs-toggle="dropdown" href="#" role="button"
                           data-bs-auto-close="outside" data-bs-display="static" aria-expanded="false" aria-label="Notifications">
                            <i class="feather-bell"></i>
                            <span class="badge bg-danger nxl-h-badge" id="esnBadge" hidden></span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end nxl-h-dropdown nxl-notifications-menu esn-menu">
                            <div class="esn-head">
                                <h6><i class="feather-bell text-primary"></i> Notifications <span class="esn-pill" id="esnHeadCount" hidden></span></h6>
                                <button type="button" class="esn-linkbtn" id="esnMarkAll" data-esn-markall disabled>Mark all as read</button>
                            </div>
                            <div class="esn-filters">
                                <div class="esn-seg" role="group" aria-label="Show">
                                    <button type="button" class="active" data-esn-state="all" aria-pressed="true">All</button>
                                    <button type="button" data-esn-state="unread" aria-pressed="false">Unread</button>
                                </div>
                                <!-- Shared custom dropdown (assets/js/custom-select.js): data-cs-filter = list floats, never clipped;
                                     data-cs-search = focus goes to a (visually hidden) type-ahead input, so Bootstrap's
                                     dropdown arrow-key handling (which skips inputs) can't take the arrow keys -->
                                <select id="esnCat" class="js-custom-select esn-cat" data-cs-filter data-cs-search data-cs-search-placeholder="Type to find a category" aria-label="Category">
                                    <option value="">All categories</option>
                                    <?php foreach ($_snCats as $_k => $_label): ?>
                                    <option value="<?= htmlspecialchars($_k) ?>"><?= htmlspecialchars($_label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="esn-scroll" id="esnList" aria-live="polite"></div>
                            <div class="esn-foot">
                                <a href="notification-center.php"><i class="feather-list me-1"></i>View All Notifications</a>
                            </div>
                        </div>
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
                            <a href="settings.php" class="dropdown-item">
                                <i class="feather-settings me-2"></i>Settings
                            </a>
                            <div class="dropdown-divider"></div>
                            <a href="logout.php" class="dropdown-item text-danger" data-signout-confirm>
                                <i class="feather-log-out me-2"></i>Sign Out
                            </a>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </header>
    <!-- Sign-out confirmation shared with the patient portal (handles a[data-signout-confirm]) -->
    <script src="assets/js/signout-confirm.js?v=3"></script>
    <link rel="stylesheet" href="assets/css/es-notifications.css?v=7">
    <script src="assets/js/es-notifications.js?v=4" defer></script>