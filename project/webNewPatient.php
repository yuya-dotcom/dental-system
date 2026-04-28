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
    <link rel="stylesheet" href="assets/css/webNewPatient.css">

</head>
<body>
    <?php include("partials/appointmentWeb-Navbar.php") ?>

    <main>
        <section id="hero" class="hero d-flex align-items-start justify-content-start position-relative">
            <img src="assets/images/hero-bg-abstract.jpg" alt="" data-aos="fade-in">

            <div class="calendar-container align-items-start">
                <div class="calendar-wrapper rounded-3">

                    <!-- ── Left: Instructions + Branch ── -->
                    <aside class="instructions">
                        <h3 class="mt-2 fw-bold">Schedule an Appointment</h3>
                        <p class="highlight">Exceptional Dental Care for a Brighter Smile Today.</p>

                        <h5 class="fw-bold">For New Patients</h5>
                        <ul class="step-list">
                            <li>
                                <span class="step-num">1</span>
                                <div><strong>Select a Branch</strong>Pick the clinic closest to you.</div>
                            </li>
                            <li>
                                <span class="step-num">2</span>
                                <div><strong>Choose a Day</strong>Click any available date on the calendar.</div>
                            </li>
                            <li>
                                <span class="step-num">3</span>
                                <div><strong>Pick a Time Slot</strong>Select a time that fits your schedule.</div>
                            </li>
                            <li>
                                <span class="step-num">4</span>
                                <div><strong>Fill Out the Form</strong>Quick patient info for your records.</div>
                            </li>
                        </ul>

                        <div class="branch-label">Branch</div>
                        <div class="appBranchFilter border d-flex align-items-center rounded-2 p-1">
                            <select name="appBranch" id="appBranchFilter" class="branch-select border-0 ps-2 py-1 pe-5 fs-6">
                                <option value="">Select branch</option>
                                <!-- Populated dynamically via api/get_branches.php -->
                            </select>
                        </div>
                    </aside>

                    <!-- ── Center: Calendar ── -->
                    <div class="booking-center">
                        <h3 class="mt-2 mb-3 fw-bold">Select a date</h3>
                        <div id="calendar"></div>
                    </div>

                    <!-- ── Right: Time Slots ── -->
                    <div class="booking-right">
                        <h4 id="selectedDate" class="mt-4 mb-4 text-muted">Select time</h4>
                        <div id="timeSlots" class="time-slots"></div>
                    </div>

                </div>
            </div>
        </section>
    </main>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.20/index.global.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/fullCalendar.js"></script>

</body>
</html>