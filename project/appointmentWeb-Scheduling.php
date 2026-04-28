<?php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Book an Appointment</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/select2.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/select2-theme.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/appointmentWeb-Scheduling.css">

</head>
<body>
    <?php include("partials/appointmentWeb-Navbar.php") ?>

    <main class="calendar-container d-flex justify-content-center">
        <div class="calendar-wrapper d-grid bg-white px-4 py-5 rounded-4 m-5">
            
        <!-- ── Left: Instructions + Branch ── -->
        <aside class="instructions mx-1">
            <h2 class="text-primary">Schedule an Appointment</h2>
            <p class="highlight">Exceptional Dental Care for a Brighter Smile Today.</p>

            <h4 class="text-primary"> For New Patients</h4>

            <ul class="step-list mt-4">
                <li>
                    <span class="step-num">1</span>
                    <div class="mb-2">
                        <h5 class="mt-1"> Select a Branch </h5>
                        <p> Pick the clinic closest to you. </p>
                    </div>
                </li>
                <li>
                    <span class="step-num">2</span>
                    <div class="mb-2">
                        <h5 class="mt-1"> Choose a Day </h5>
                        <p> Click any available date on the calendar. </p>
                    </div>
                </li>
                <li>
                    <span class="step-num">3</span>
                    <div class="mb-2">
                        <h5 class="mt-1"> Pick a Time Slot </h5>
                        <p> Select a time that fits your schedule. </p>
                    </div>
                </li>
                <li>
                    <span class="step-num">4</span>
                    <div>
                        <h5 class="mt-1"> Fill Out the Form </h5>
                        <p> Quick patient info for your records. </p>
                    </div>
                </li>
            </ul>

            <h5 class="branch-label text-primary mt-2 mb-1">Pick a Branch</h5>
            <div class="appBranchFilter border d-flex align-items-center rounded-2 p-1">
                <select name="appBranch" id="appBranchFilter" class="branch-select border-0 ps-2 py-1 pe-5 fs-6">
                    <option value="">Select branch</option>
                </select>
            </div>
        </aside>
        <!-- ── Center: Calendar ── -->
            <div class="booking-center">
                <h2 class="mb-3">Select a date</h2>
                <div id="calendar"></div>
            </div>

            <!-- ── Right: Time Slots ── -->
            <div class="booking-right">
                <h2 id="selectedDate" class="mb-3 text-primary">Select time</h2>
                <div id="timeSlots" class="time-slots"></div>
            </div>
        </div>
    </main>

    <!-- ============================================================
         BOOKING MODAL
         ============================================================ -->
    <div class="modal fade" id="personalInfoModal" tabindex="-1" aria-labelledby="personalInfoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">

                <!-- Header -->
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold" id="personalInfoModalLabel">
                            <i class="feather-calendar me-2 text-primary"></i>Complete Your Booking
                        </h5>
                        <p class="text-muted small mb-0">Fill in your details to confirm the appointment.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body pt-3">

                    <!-- Appointment Summary Chip -->
                    <div class="booking-summary-chip mb-4">
                        <div class="d-flex flex-wrap gap-3">
                            <div class="d-flex align-items-center gap-2">
                                <i class="feather-calendar text-primary"></i>
                                <span class="small fw-semibold" id="modalSummaryDate">—</span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <i class="feather-clock text-primary"></i>
                                <span class="small fw-semibold" id="modalSummaryTime">—</span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <i class="feather-map-pin text-primary"></i>
                                <span class="small fw-semibold" id="modalSummaryBranch">—</span>
                            </div>
                        </div>
                    </div>

                    <!-- Error Alert -->
                    <div id="modalError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;"></div>

                    <!-- Patient Form -->
                    <div class="row g-3">

                        <!-- Last Name + Suffix -->
                        <div class="col-md-8">
                            <label for="modal_lastName" class="form-label fw-semibold small">
                                Last Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="modal_lastName" class="form-control"
                                placeholder="e.g. Dela Cruz" autocomplete="family-name">
                            <div class="text-danger small mt-1" id="err_lastName"></div>
                        </div>
                        <div class="col-md-4">
                            <label for="modal_suffix" class="form-label fw-semibold small">
                                Suffix <span class="text-muted fw-normal">(optional)</span>
                            </label>
                            <select id="modal_suffix" class="form-select">
                                <option value="">None</option>
                                <option value="Jr.">Jr.</option>
                                <option value="Sr.">Sr.</option>
                                <option value="II">II</option>
                                <option value="III">III</option>
                                <option value="IV">IV</option>
                            </select>
                        </div>

                        <!-- First Name + Middle Name -->
                        <div class="col-md-6">
                            <label for="modal_firstName" class="form-label fw-semibold small">
                                First Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="modal_firstName" class="form-control"
                                placeholder="e.g. Juan" autocomplete="given-name">
                            <div class="text-danger small mt-1" id="err_firstName"></div>
                        </div>
                        <div class="col-md-6">
                            <label for="modal_middleName" class="form-label fw-semibold small">
                                Middle Name <span class="text-muted fw-normal">(optional)</span>
                            </label>
                            <input type="text" id="modal_middleName" class="form-control"
                                placeholder="e.g. Santos" autocomplete="additional-name">
                        </div>

                        <!-- Birthdate -->
                        <div class="col-12">
                            <label for="modal_birthdate" class="form-label fw-semibold small">
                                Birthdate <span class="text-danger">*</span>
                            </label>
                            <input type="date" id="modal_birthdate" class="form-control">
                            <div class="text-danger small mt-1" id="err_birthdate"></div>
                        </div>

                        <!-- Service -->
                        <div class="col-12">
                            <label for="modal_service" class="form-label fw-semibold small">
                                Service <span class="text-muted fw-normal">(optional)</span>
                            </label>
                            <select id="modal_service" class="form-select">
                                <option value="">— Select a service —</option>
                                <!-- Populated dynamically by fullCalendar.js via api/get_services.php -->
                            </select>
                        </div>

                        <!-- Contact Number -->
                        <div class="col-12">
                            <label for="modal_contactNumber" class="form-label fw-semibold small">
                                Contact Number <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text fw-semibold text-muted">+63</span>
                                <input type="tel" id="modal_contactNumber" class="form-control"
                                    placeholder="9XX XXX XXXX" maxlength="10" autocomplete="tel"
                                    oninput="this.value = this.value.replace(/\D/g,'').substring(0,10)">
                            </div>
                            <div class="text-danger small mt-1" id="err_contactNumber"></div>
                        </div>

                    </div>

                    <!-- Note -->
                    <p class="text-muted small mt-3 mb-0">
                        <i class="feather-info me-1"></i>
                        Your contact number identifies your record on future visits. No account needed.
                    </p>

                </div>

                <!-- Footer -->
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="modalConfirmBtn" class="btn btn-primary px-4"
                        onclick="submitBookingModal()">Confirm Appointment</button>
                </div>

            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.20/index.global.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/fullCalendar.js"></script>
</body>
</html>