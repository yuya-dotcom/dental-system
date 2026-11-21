<?php
// =============================================================
//  partials/inventory-modals.php — Inventory Records modals
//  Details (read), Add / Edit item, Log Movement. Driven by
//  assets/js/inventory.js; data from api/inventory_data.php.
//  Buttons: primary action LEFT, Cancel / Close RIGHT.
// =============================================================
?>
    <!-- ══ DETAILS ════════════════════════════════════════════ -->
    <div class="modal fade es-detail-modal" id="inventoryViewModal" tabindex="-1" aria-labelledby="ivName" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-record-dialog rec-detail-dialog inv-detail-dialog">
            <div class="modal-content apt-record-content">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-package"></i></div>
                        <div>
                            <h5 class="apt-record-title mb-0" id="ivName">Item</h5>
                            <div class="apt-record-meta"><span class="apt-record-code" id="ivCode">—</span> · <span id="ivBranch">—</span></div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-record-body p-0">
                    <div class="inv-loading" id="ivLoading"><span class="spinner-border spinner-border-sm me-2"></span>Loading item…</div>
                    <div class="ar-view-body" id="ivBody" hidden>
                        <div class="ar-summary rec-summary-4">
                            <div class="ar-sum-item ar-sum-status"><div class="ar-sum-label">Status</div><div id="ivStatus"></div></div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-layers"></i></span>
                                <div class="ar-sum-text"><div class="ar-sum-label">In Stock</div><div class="ar-sum-value" id="ivStock">—</div></div>
                            </div>
                            <div class="ar-sum-item">
                                <span class="ar-sum-icon"><i class="feather-bell"></i></span>
                                <div class="ar-sum-text"><div class="ar-sum-label">Minimum / Reorder</div><div class="ar-sum-value" id="ivMin">—</div></div>
                            </div>
                            <div class="ar-sum-item" id="ivValueWrap">
                                <span class="ar-sum-icon"><i class="feather-dollar-sign"></i></span>
                                <div class="ar-sum-text"><div class="ar-sum-label">Stock Value</div><div class="ar-sum-value" id="ivValue">—</div></div>
                            </div>
                        </div>
                        <div id="ivAlerts"></div>
                        <div class="ar-cards-grid">
                            <section class="ar-card">
                                <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-info"></i></span><h6 class="ar-card-title">Item Details</h6></div>
                                <div class="ar-card-fields">
                                    <div class="ar-card-field"><span class="ar-card-label">Category</span><span class="ar-card-value" id="ivCat">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Unit</span><span class="ar-card-value" id="ivUnit">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Unit cost</span><span class="ar-card-value" id="ivCost">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Supplier</span><span class="ar-card-value" id="ivSupplier">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Expiry dates</span><span class="ar-card-value" id="ivTrack">—</span></div>
                                    <div class="ar-card-field"><span class="ar-card-label">Last updated</span><span class="ar-card-value" id="ivUpdated">—</span></div>
                                </div>
                            </section>
                            <section class="ar-card" id="ivOthersCard" hidden>
                                <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-map-pin"></i></span><h6 class="ar-card-title">Other Branches</h6></div>
                                <div class="ar-card-fields"><ul class="inv-mini-list" id="ivOthers"></ul></div>
                            </section>
                            <section class="ar-card ar-card-wide" id="ivBatchCard" hidden>
                                <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-clock"></i></span><h6 class="ar-card-title">Batches &amp; Expiry</h6></div>
                                <div class="ar-card-fields p-0"><div class="table-responsive"><table class="table inv-mini-table mb-0">
                                    <thead><tr><th>Lot / Batch No.</th><th>Expiry</th><th class="text-end">Remaining</th><th>Received</th></tr></thead>
                                    <tbody id="ivBatches"></tbody>
                                </table></div></div>
                            </section>
                            <section class="ar-card ar-card-wide">
                                <div class="ar-card-header">
                                    <span class="ar-card-icon"><i class="feather-refresh-cw"></i></span><h6 class="ar-card-title">Recent Stock Movements</h6>
                                    <a href="#" class="ms-auto small fw-semibold" id="ivAllMov">Full history <i class="feather-chevron-right"></i></a>
                                </div>
                                <div class="ar-card-fields p-0"><div class="table-responsive"><table class="table inv-mini-table mb-0">
                                    <thead><tr><th>Date</th><th>Type</th><th class="text-end">Change</th><th class="text-end">Stock</th><th>Reason</th><th>By</th></tr></thead>
                                    <tbody id="ivMovements"></tbody>
                                </table></div></div>
                            </section>
                        </div>
                    </div>
                </div>
                <div class="modal-footer apt-record-footer">
                    <?php if (!isDentist()): ?>
                    <button type="button" class="btn btn-primary" id="ivMoveBtn"><i class="feather-plus-circle me-1"></i> Log Movement</button>
                    <button type="button" class="btn btn-light-brand" id="ivEditBtn"><i class="feather-edit-2 me-1"></i> Edit Item</button>
                    <?php endif; ?>
                    <span class="rec-foot-spacer"></span>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php if (!isDentist()): ?>
    <!-- ══ ADD / EDIT ═════════════════════════════════════════ -->
    <div class="modal fade es-detail-modal" id="inventoryModal" tabindex="-1" aria-labelledby="invModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-form-dialog rec-form-dialog">
            <form class="modal-content apt-record-content" id="inventoryForm" novalidate onsubmit="event.preventDefault(); saveItem();">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-package"></i></div>
                        <div>
                            <h5 class="apt-record-title mb-0" id="invModalTitle">Add Inventory Item</h5>
                            <div class="apt-record-meta" id="invModalSub">An item code is assigned automatically.</div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-form-body">
                    <div id="invModalError" class="apt-form-alert" role="alert" style="display:none;"></div>
                    <input type="hidden" id="inv_id">
                    <div class="rec-form-stack">
                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-tag"></i></span><h6 class="ar-card-title">Item Details</h6></div>
                            <div class="apt-form-card-body">
                                <div class="apt-field">
                                    <label class="apt-label" for="inv_name">Item Name <span class="apt-req" aria-hidden="true">*</span></label>
                                    <input type="text" id="inv_name" class="form-control" maxlength="120" autocomplete="off" placeholder="e.g. Disposable Gloves">
                                    <div class="rec-help" id="invNameHelp">Type the exact name of an existing item to stock it at another branch, or a new name.</div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6"><div class="apt-field mb-0">
                                        <?php /* Category: dropdown of the categories already in use (filled by inventory.js);
                                                 "Add new category…" reveals #inv_category_new. The saved value is read
                                                 through invCategoryValue() — the database still stores plain text. */ ?>
                                        <label class="apt-label" for="inv_category">Category</label>
                                        <select id="inv_category" class="form-select js-custom-select inv-shared" data-cs-float data-cs-search data-cs-search-placeholder="Search categories…">
                                            <option value="">No category</option>
                                        </select>
                                        <input type="text" id="inv_category_new" class="form-control inv-shared mt-2" maxlength="60" autocomplete="off" placeholder="New category name, e.g. Consumable" aria-label="New category name" hidden>
                                    </div></div>
                                    <div class="col-md-6 inv-v2"><div class="apt-field mb-0">
                                        <label class="apt-label" for="inv_unit">Unit of Measurement</label>
                                        <select id="inv_unit" class="form-select js-custom-select inv-shared" data-cs-float></select>
                                    </div></div>
                                    <div class="col-md-6 inv-v2"><div class="apt-field mb-0">
                                        <label class="apt-label" for="inv_supplier">Supplier</label>
                                        <select id="inv_supplier" class="form-select js-custom-select inv-shared" data-cs-float data-cs-search data-cs-search-placeholder="Search suppliers…"><option value="">None</option></select>
                                    </div></div>
                                    <div class="col-md-6 inv-v2"><div class="apt-field mb-0">
                                        <label class="apt-label" for="inv_cost">Unit Cost (₱)
                                            <button type="button" class="rec-info" data-bs-title="Used for stock value. Updated automatically from purchase deliveries." aria-label="About unit cost: used for stock value, and updated automatically from purchase deliveries."><i class="feather-info"></i></button>
                                        </label>
                                        <input type="number" id="inv_cost" class="form-control inv-shared" min="0" step="0.01" placeholder="e.g. 12.50">
                                    </div></div>
                                    <div class="col-12 inv-v2">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input inv-shared" type="checkbox" id="inv_track" onchange="invToggleOpeningBatch()">
                                            <label class="form-check-label small fw-semibold" for="inv_track">Track lot numbers &amp; expiry dates</label>
                                        </div>
                                        <div class="rec-help">Turn on for medicines, anesthetics and other items that expire.</div>
                                    </div>
                                </div>
                                <div class="rec-note mt-3" id="invSharedNote" hidden><i class="feather-info"></i><span>Name, category, unit, supplier, cost and expiry tracking are shared by every branch that stocks this item.</span></div>
                            </div>
                        </section>
                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-map-pin"></i></span><h6 class="ar-card-title">Branch Stock Levels</h6></div>
                            <div class="apt-form-card-body">
                                <div class="row g-3">
                                    <div class="col-md-6"><div class="apt-field mb-0">
                                        <label class="apt-label" for="inv_branch">Branch <span class="apt-req" aria-hidden="true">*</span></label>
                                        <select id="inv_branch" class="form-select js-custom-select" data-cs-float></select>
                                    </div></div>
                                    <div class="col-md-6"><div class="apt-field mb-0">
                                        <label class="apt-label" for="inv_stock" id="invStockLabel">Opening Stock</label>
                                        <input type="number" id="inv_stock" class="form-control" value="0" min="0" oninput="invToggleOpeningBatch()">
                                        <div class="rec-help" id="invStockHelp">Units on hand right now. Recorded as the first Stock In.</div>
                                    </div></div>
                                    <div class="col-md-6"><div class="apt-field mb-0">
                                        <label class="apt-label" for="inv_min_stock">Minimum Stock <span class="apt-req" aria-hidden="true">*</span></label>
                                        <input type="number" id="inv_min_stock" class="form-control" value="0" min="0">
                                        <div class="rec-help">You are alerted when stock falls to this level.</div>
                                    </div></div>
                                    <div class="col-md-6 inv-v2"><div class="apt-field mb-0">
                                        <label class="apt-label" for="inv_reorder">Reorder Quantity</label>
                                        <input type="number" id="inv_reorder" class="form-control" value="0" min="0">
                                        <div class="rec-help">How much to order when restocking (e.g. 30).</div>
                                    </div></div>
                                </div>
                            </div>
                        </section>
                        <section class="ar-card inv-v2" id="invOpeningBatch" hidden>
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-clock"></i></span><h6 class="ar-card-title">Opening Stock Batch</h6></div>
                            <div class="apt-form-card-body">
                                <div class="row g-3">
                                    <div class="col-md-4"><div class="apt-field mb-0">
                                        <label class="apt-label" for="inv_lot">Lot / Batch No.</label>
                                        <input type="text" id="inv_lot" class="form-control" maxlength="60" placeholder="Optional">
                                    </div></div>
                                    <div class="col-md-4"><div class="apt-field mb-0">
                                        <label class="apt-label" for="inv_exp">Expiry Date <span class="apt-req" aria-hidden="true">*</span></label>
                                        <input type="date" id="inv_exp" class="form-control js-date-picker">
                                    </div></div>
                                    <div class="col-md-4"><div class="apt-field mb-0">
                                        <label class="apt-label" for="inv_mfd">Manufactured</label>
                                        <input type="date" id="inv_mfd" class="form-control js-date-picker">
                                    </div></div>
                                </div>
                            </div>
                        </section>
                        <div class="rec-note mb-0"><i class="feather-info"></i><span>Status (In Stock / Low / Out) is set automatically. Stock is changed with <strong>Log Movement</strong> so every change has a reason.</span></div>
                    </div>
                </div>
                <div class="modal-footer apt-record-footer apt-form-footer">
                    <span class="apt-form-hint"><span class="apt-req">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4" id="invSaveBtn"><i class="feather-save me-1"></i> Save Item</button>
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ══ LOG MOVEMENT ═══════════════════════════════════════ -->
    <div class="modal fade es-detail-modal" id="movementModal" tabindex="-1" aria-labelledby="movTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down apt-form-dialog rec-form-dialog">
            <form class="modal-content apt-record-content" id="movementForm" novalidate onsubmit="event.preventDefault(); saveMovement();">
                <div class="modal-header apt-record-header">
                    <div class="apt-record-title-wrap">
                        <div class="apt-record-icon" aria-hidden="true"><i class="feather-refresh-cw"></i></div>
                        <div>
                            <h5 class="apt-record-title mb-0" id="movTitle">Log Stock Movement</h5>
                            <div class="apt-record-meta" id="movItemName">—</div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body apt-form-body">
                    <div id="movModalError" class="apt-form-alert" role="alert" style="display:none;"></div>
                    <input type="hidden" id="mov_item_id">
                    <div class="rec-form-stack">
                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-list"></i></span><h6 class="ar-card-title">What happened?</h6></div>
                            <div class="apt-form-card-body">
                                <div class="rec-seg inv-type-seg" id="movTypes" role="radiogroup" aria-label="Movement type"></div>
                            </div>
                        </section>
                        <section class="ar-card">
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-hash"></i></span><h6 class="ar-card-title">Quantity &amp; Reason</h6></div>
                            <div class="apt-form-card-body">
                                <div class="row g-3">
                                    <div class="col-md-4"><div class="apt-field mb-0">
                                        <span class="apt-label">Current Stock</span>
                                        <div class="inv-current" id="movCurrentStock" aria-live="polite">—</div>
                                    </div></div>
                                    <div class="col-md-4"><div class="apt-field mb-0">
                                        <label class="apt-label" for="mov_quantity" id="movQtyLabel">Quantity <span class="apt-req" aria-hidden="true">*</span></label>
                                        <input type="number" id="mov_quantity" class="form-control" placeholder="e.g. 10" min="0" oninput="updateMovPreview()">
                                    </div></div>
                                    <div class="col-md-4"><div class="apt-field mb-0">
                                        <span class="apt-label">New Stock</span>
                                        <div class="inv-current" id="movStockPreview" aria-live="polite">—</div>
                                    </div></div>
                                    <div class="col-12"><div class="inv-hint" id="movHint" hidden></div></div>
                                    <div class="col-md-8"><div class="apt-field mb-0">
                                        <label class="apt-label" for="mov_reason">Reason <span class="apt-req" aria-hidden="true">*</span></label>
                                        <input type="text" id="mov_reason" class="form-control" maxlength="250" placeholder="e.g. Restocked from supplier">
                                    </div></div>
                                    <div class="col-md-4 inv-v2"><div class="apt-field mb-0">
                                        <label class="apt-label" for="mov_ref">Reference No.</label>
                                        <input type="text" id="mov_ref" class="form-control" maxlength="60" placeholder="Optional, e.g. DR-1043">
                                    </div></div>
                                </div>
                            </div>
                        </section>
                        <section class="ar-card inv-v2" id="movBatchIn" hidden>
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-clock"></i></span><h6 class="ar-card-title">Batch Details</h6></div>
                            <div class="apt-form-card-body">
                                <div class="row g-3">
                                    <div class="col-md-4"><div class="apt-field mb-0">
                                        <label class="apt-label" for="mov_lot">Lot / Batch No.</label>
                                        <input type="text" id="mov_lot" class="form-control" maxlength="60" placeholder="Optional">
                                    </div></div>
                                    <div class="col-md-4"><div class="apt-field mb-0">
                                        <label class="apt-label" for="mov_exp">Expiry Date <span class="apt-req" id="movExpReq" aria-hidden="true">*</span></label>
                                        <input type="date" id="mov_exp" class="form-control js-date-picker">
                                    </div></div>
                                    <div class="col-md-4"><div class="apt-field mb-0">
                                        <label class="apt-label" for="mov_mfd">Manufactured</label>
                                        <input type="date" id="mov_mfd" class="form-control js-date-picker">
                                    </div></div>
                                </div>
                            </div>
                        </section>
                        <section class="ar-card inv-v2" id="movBatchOut" hidden>
                            <div class="ar-card-header"><span class="ar-card-icon"><i class="feather-clock"></i></span><h6 class="ar-card-title">Take From Batch</h6></div>
                            <div class="apt-form-card-body">
                                <div class="apt-field mb-0">
                                    <label class="apt-label" for="mov_batch">Batch</label>
                                    <select id="mov_batch" class="form-select js-custom-select" data-cs-float onchange="updateMovPreview()"></select>
                                    <div class="rec-help">Automatic uses the batch that expires first.</div>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
                <div class="modal-footer apt-record-footer apt-form-footer">
                    <span class="apt-form-hint"><span class="apt-req">*</span> Required</span>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4" id="movSaveBtn"><i class="feather-check me-1"></i> Save Movement</button>
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
