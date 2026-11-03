<?php
// portal-terms.php
// Publicly viewable Terms and Conditions for the EssenciaSmile patient portal.
// Linked from the registration checkbox on portal-login.php (opens in a new tab),
// and sits alongside contact-us-page.php under the same "Information" section.
session_start();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms and Conditions | EssenciaSmile Patient Portal</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <link rel="stylesheet" href="assets/css/portal-info.css">
    <link rel="stylesheet" href="assets/css/portalLogin-navbar.css">
</head>

<body>

    <!-- ── Top bar ───────────────────────────────────────────────────────── -->
    <?php include("partials/portalLogin-navbar.php") ?>

    <!-- ── Page body ─────────────────────────────────────────────────────── -->
    <div class="info-page">

        <!-- ── Information navigation ──────────────────────────────────── -->
        <aside class="info-sidebar">
            <div class="info-sidebar-inner">
                <span class="portal-pill">Patient Portal</span>
                <h3 class="info-sidebar-title">Information</h3>
                <nav class="info-nav">
                    <a href="contact-us-page.php" class="info-nav-link">
                        <i class="fa-solid fa-address-book"></i> Contact Us
                    </a>
                    <a href="portal-terms.php" class="info-nav-link active">
                        <i class="fa-solid fa-file-shield"></i> Terms and Conditions
                    </a>
                </nav>
            </div>
        </aside>

        <!-- ── Terms content ─────────────────────────────────────────────── -->
        <main class="info-content">
            <div class="terms-card">

                <div class="terms-header">
                    <div class="modal-icon"><i class="fa-solid fa-file-shield"></i></div>
                    <div>
                        <h1>Terms and Conditions</h1>
                        <p class="terms-updated">Last updated: September 14, 2026</p>
                    </div>
                </div>

                <p>
                    Welcome to the EssenciaSmile Patient Portal. By creating an account and
                    using this portal, you agree to the terms below. Please read them
                    carefully before registering.
                </p>

                <div class="terms-section">
                    <h2><span>1.</span>Acceptance of Terms</h2>
                    <p>
                    By checking the "I agree to the Terms and Conditions" box during
                    registration, you confirm that you have read, understood, and agree to
                    be bound by these terms. If you do not agree, please do not create a
                    patient portal account.
                </p>
                </div>

                <div class="terms-section">
                    <h2><span>2.</span>Account Registration</h2>
                    <p>
                    You must provide accurate, current, and complete information when
                    registering, including your name, birthdate, contact details, and
                    address. You are responsible for keeping your login credentials
                    confidential and for all activity that occurs under your account.
                </p>
                </div>

                <div class="terms-section">
                    <h2><span>3.</span>Use of the Portal</h2>
                    <p>
                    The patient portal is provided so you can book appointments, view your
                    dental records and treatment history, and check billing information.
                    You agree to use the portal only for its intended purpose and not to
                    attempt to access another patient's records or misuse any part of the
                    system.
                </p>
                </div>

                <div class="terms-section">
                    <h2><span>4.</span>Appointments and Cancellations</h2>
                    <p>
                    Appointments booked through the portal are subject to confirmation and
                    availability at the selected branch. Please cancel or reschedule as
                    early as possible if you are unable to attend, so the slot can be
                    offered to another patient.
                </p>
                </div>

                <div class="terms-section">
                    <h2><span>5.</span>Medical and Health Information</h2>
                    <p>
                    Information you provide through the medical questionnaire and your
                    profile (such as allergies, existing conditions, and medications) is
                    used by our dentists to provide safe and appropriate care. You agree to
                    keep this information accurate and to update it if your health
                    situation changes.
                </p>
                </div>

                <div class="terms-section">
                    <h2><span>6.</span>Privacy and Data Protection</h2>
                    <p>
                    Your personal and medical information is collected and processed in
                    accordance with the Philippine Data Privacy Act of 2012. We use your
                    data only to manage your appointments, treatments, and billing with
                    EssenciaSmile, and we do not sell your information to third parties.
                </p>
                </div>

                <div class="terms-section">
                    <h2><span>7.</span>Fees and Payment</h2>
                    <p>
                    Treatment fees, billing details, and payment status shown in the portal
                    are for your reference. Actual charges are confirmed at your branch of
                    treatment and are subject to the clinic's current price list.
                </p>
                </div>

                <div class="terms-section">
                    <h2><span>8.</span>Limitation of Liability</h2>
                    <p>
                    The portal is provided on an "as is" basis to help you manage your
                    dental care. While we take reasonable steps to keep information
                    accurate and the system available, EssenciaSmile is not liable for
                    delays, errors, or interruptions beyond our reasonable control.
                </p>
                </div>

                <div class="terms-section">
                    <h2><span>9.</span>Changes to These Terms</h2>
                    <p>
                    We may update these Terms and Conditions from time to time to reflect
                    changes in our services or applicable law. Continued use of the portal
                    after changes are posted means you accept the revised terms.
                </p>
                </div>

                <div class="terms-section">
                    <h2><span>10.</span>Contact Us</h2>
                    <p>
                    If you have questions about these Terms and Conditions or how your
                    information is used, please get in touch with your EssenciaSmile
                    branch directly.
                </p>
                </div>

                <div class="terms-back-row">
                    <a href="portal-login.php" class="foot-link">
                        <i class="fa-solid fa-arrow-left"></i> Back to Portal
                    </a>
                </div>

            </div>
        </main>

    </div>

</body>

</html>
