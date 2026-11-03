<?php
// contact-us-page.php
// Publicly viewable Contact Us page for the EssenciaSmile patient portal.
// Sits alongside portal-terms.php under the same "Information" section, linked from the portal's info navigation.
session_start();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us | EssenciaSmile Patient Portal</title>
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
                    <a href="contact-us-page.php" class="info-nav-link active">
                        <i class="fa-solid fa-address-book"></i> Contact Us
                    </a>
                    <a href="portal-terms.php" class="info-nav-link">
                        <i class="fa-solid fa-file-shield"></i> Terms and Conditions
                    </a>
                </nav>
            </div>
        </aside>

        <!-- ── Contact content ──────────────────────────────────────────── -->
        <main class="info-content">
            <div class="contact-card">

                <div class="terms-header">
                    <div class="modal-icon"><i class="fa-solid fa-address-book"></i></div>
                    <div>
                        <h1>Contact Us</h1>
                        <p class="terms-updated">We'd love to hear from you</p>
                    </div>
                </div>

                <p class="contact-welcome">
                    Have a question about your appointment, treatment, or account?
                    Our team is happy to help. Reach out through any of the details
                    below, or visit us at your nearest EssenciaSmile branch.
                </p>

                <!-- ── Quick contact ── -->
                <div class="contact-quick-grid">
                    <div class="contact-quick-item">
                        <div class="cq-icon"><i class="fa-solid fa-phone"></i></div>
                        <div>
                            <span class="cq-label">Phone</span>
                            <span class="cq-value">+639 123 456 789</span>
                        </div>
                    </div>
                    <div class="contact-quick-item">
                        <div class="cq-icon"><i class="fa-solid fa-envelope"></i></div>
                        <div>
                            <span class="cq-label">Email</span>
                            <span class="cq-value">essencia.smile@gmail.com</span>
                        </div>
                    </div>
                    <div class="contact-quick-item">
                        <div class="cq-icon"><i class="fa-solid fa-clock"></i></div>
                        <div>
                            <span class="cq-label">Clinic Hours</span>
                            <!-- TODO: confirm actual hours -->
                            <span class="cq-value">Mon–Sat, 9:00 AM – 6:00 PM</span>
                        </div>
                    </div>
                </div>

                <!-- ── Branches ── -->
                <div class="contact-section-title">
                    <i class="fa-solid fa-location-dot"></i> Our Branches
                </div>

                <div class="branch-grid">

                    <!-- TODO: replace with the real Novaliches branch details -->
                    <div class="branch-card">
                        <h3>Novaliches Branch</h3>
                        <p><i class="fa-solid fa-location-dot"></i> 108 Susano Road, Novaliches, Quezon City</p>
                        <p><i class="fa-solid fa-phone"></i> (+63) 9123 456 789</p>
                        <p><i class="fa-solid fa-clock"></i> Mon–Sat, 9:00 AM – 6:00 PM</p>
                    </div>

                    <!-- TODO: replace with the real Mandaluyong branch details -->
                    <div class="branch-card">
                        <h3>Mandaluyong Branch</h3>
                        <p><i class="fa-solid fa-location-dot"></i> 565 Sgt. Bumatay St., Mandaluyong City</p>
                        <p><i class="fa-solid fa-phone"></i> (+63) 9123 456 789</p>
                        <p><i class="fa-solid fa-clock"></i> Mon–Sat, 9:00 AM – 6:00 PM</p>
                    </div>

                    <!-- TODO: replace with the real Caloocan branch details -->
                    <div class="branch-card">
                        <h3>Caloocan Branch</h3>
                        <p><i class="fa-solid fa-location-dot"></i> 132 Vista Verde Ave., Caloocan City</p>
                        <p><i class="fa-solid fa-phone"></i> (+63) 9123 456 789</p>
                        <p><i class="fa-solid fa-clock"></i> Mon–Sat, 9:00 AM – 6:00 PM</p>
                    </div>

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
