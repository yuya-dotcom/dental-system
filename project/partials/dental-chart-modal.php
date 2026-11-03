<div class="modal fade" id="dentalChartModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">

            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold mb-0">
                        <i class="feather-grid me-2 text-primary"></i>Update Dental Chart
                    </h5>
                    <p class="text-muted small mb-0" id="dcModalSubtitle">Loading…</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="dc-changes-badge" id="dcChangesBadge" style="display:none !important;">
                        <i class="feather-alert-circle" style="font-size:11px;"></i>
                        <span id="dcChangesCount">0</span> unsaved
                    </span>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
            </div>

            <div class="modal-body pt-2">
                <div id="dcError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>

                <input type="hidden" id="dc_patient_id">
                <input type="hidden" id="dc_treatment_tooth">

                <div class="dc-modal-grid">

                    <div>
                        <div class="dc-legend mb-2">
                            <span class="dc-legend-item"><span class="dc-legend-dot" style="background:#fff;border-color:#86efac;"></span>Healthy</span>
                            <span class="dc-legend-item"><span class="dc-legend-dot" style="background:#3b82f6;border-color:#2563eb;"></span>Filled</span>
                            <span class="dc-legend-item"><span class="dc-legend-dot" style="background:#ef4444;border-color:#dc2626;"></span>Decay</span>
                            <span class="dc-legend-item"><span class="dc-legend-dot" style="background:#a855f7;border-color:#9333ea;"></span>Impacted</span>
                            <span class="dc-legend-item"><span class="dc-legend-dot" style="background:#9ca3af;border-color:#6b7280;"></span>Missing</span>
                            <span class="dc-legend-item"><span class="dc-legend-dot" style="background:#eab308;border-color:#ca8a04;"></span>Crown</span>
                            <span class="dc-legend-item"><span class="dc-legend-dot" style="background:#22c55e;border-color:#16a34a;"></span>Impacted (NE)</span>
                            <span class="dc-legend-item"><span class="dc-legend-dot" style="background:#fef9c3;border-color:#fde68a;"></span>Modified</span>
                        </div>

                        <div class="dc-section-label">Upper Teeth (18→11 | 21→28)</div>
                        <div class="dc-tooth-grid mb-3" id="dc_upperTeeth"></div>

                        <div class="dc-section-label">Lower Teeth (48→41 | 31→38)</div>
                        <div class="dc-tooth-grid" id="dc_lowerTeeth"></div>

                        <div class="mt-2 small text-muted">
                            <i class="feather-info me-1"></i>
                            Click a tooth to select it, then set conditions on the right panel.
                            Teeth highlighted from this treatment are pre-selected.
                        </div>
                    </div>

                    <div class="dc-panel">
                        <div class="dc-panel-empty" id="dcPanelEmpty">
                            <i class="feather-mouse-pointer"></i>
                            Click any tooth to<br>set its condition
                        </div>

                        <div id="dcPanelEditor" style="display:none;">
                            <div class="dc-panel-title">
                                Tooth <span id="dcActiveTooth" class="text-primary"></span>
                            </div>

                            <div class="dc-selected-label">Overall Condition</div>
                            <div class="dc-cond-grid" id="dcCondGrid"></div>

                            <div style="border-top:1px solid #e5e7eb;margin:.75rem 0 .75rem;"></div>

                            <div class="dc-selected-label">Surface Conditions
                                <span class="dc-selected-sub">(B · M · O · L · D)</span>
                            </div>

                            <div class="dc-surface-grid" id="dcSurfaceGrid"></div>

                            <div id="dcSurfacePicker" style="display:none;">
                                <div class="dc-selected-sub mb-1">Surface: <strong id="dcActiveSurface"></strong></div>
                                <select class="dc-surf-select" id="dcSurfCondSelect">
                                    <option value="healthy">Healthy</option>
                                    <option value="filled">Filled</option>
                                    <option value="decay">Decay</option>
                                    <option value="impacted">Impacted</option>
                                    <option value="missing">Missing</option>
                                    <option value="crown">Crown</option>
                                    <option value="impacted_ne">Impacted (Not Erupted)</option>
                                </select>
                                <button class="dc-surf-apply-btn" onclick="applySurfaceCondition()">
                                    Apply to this surface
                                </button>
                                <button class="dc-surf-apply-all-btn" onclick="applyToAllSurfaces()">
                                    Apply to all surfaces
                                </button>
                            </div>

                            <div style="border-top:1px solid #e5e7eb;margin:.75rem 0 .5rem;"></div>
                            <div class="dc-selected-label">Notes for this tooth</div>
                            <textarea class="dc-notes-field" id="dcToothNotes"
                                placeholder="e.g. Root canal started, caries on mesial surface…"
                                oninput="markToothModified()"></textarea>
                        </div>
                    </div>

                </div>
            </div>

            <div class="modal-footer border-0 d-flex justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <select class="form-select form-select-sm" id="dcSource" style="max-width:180px;">
                        <option value="consultation">Initial Consultation</option>
                        <option value="treatment_record">Treatment Record</option>
                        <option value="correction">Correction</option>
                    </select>
                    <span class="text-muted small">Update Type</span>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary px-4" id="dcSaveBtn" onclick="saveDentalChart()">
                        <i class="feather-save me-1"></i> Save Chart
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>