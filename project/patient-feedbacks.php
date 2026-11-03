<?php
// patient-feedbacks.php
// Publicly viewable patient feedback form for the EssenciaSmile patient portal.
// Sits alongside contact-us.php and portal-terms.php under the same
// "Information" section, linked from the portal's info navigation.
//
// Patient Side only for now — this form validates and shows a thank-you
// state client-side. Actually saving/moderating feedback (and turning
// approved entries into landing-page testimonials) is the Admin Side,
// to be wired up once that code is provided. See the TODO in the
// <script> block below for where that submission call will go.
session_start();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Feedback | EssenciaSmile Patient Portal</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <link rel="stylesheet" href="assets/css/portal-info.css">
    <link rel="stylesheet" href="assets/css/patient-feedbacks.css">
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
                    <a href="portal-terms.php" class="info-nav-link">
                        <i class="fa-solid fa-file-shield"></i> Terms and Conditions
                    </a>
                    <a href="patient-feedbacks.php" class="info-nav-link active">
                        <i class="fa-solid fa-comment-dots"></i> Feedback
                    </a>
                </nav>
            </div>
        </aside>

        <!-- ── Feedback content ──────────────────────────────────────────── -->
        <main class="info-content">
            <div class="feedback-card">

                <div class="terms-header">
                    <div class="modal-icon"><i class="fa-solid fa-comment-dots"></i></div>
                    <div>
                        <h1>Patient Feedback</h1>
                        <p class="terms-updated">Tell us about your visit</p>
                    </div>
                </div>

                <p class="contact-welcome">
                    We'd love to hear how your appointment went. Your feedback helps
                    us improve, and — with your permission — may be shared to help
                    other patients know what to expect.
                </p>

                <!-- ── Form ── -->
                <form id="feedbackForm" novalidate>

                    <div id="feedbackAlert" class="alert-bar"></div>

                    <!-- Rating -->
                    <div class="field">
                        <label>Overall Rating</label>
                        <div class="star-rating">
                            <input type="radio" id="star5" name="rating" value="5">
                            <label for="star5" title="5 stars"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="star4" name="rating" value="4">
                            <label for="star4" title="4 stars"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="star3" name="rating" value="3">
                            <label for="star3" title="3 stars"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="star2" name="rating" value="2">
                            <label for="star2" title="2 stars"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="star1" name="rating" value="1">
                            <label for="star1" title="1 star"><i class="fa-solid fa-star"></i></label>
                        </div>
                        <div class="field-err" id="errRating"></div>
                    </div>

                    <!-- Message -->
                    <div class="field">
                        <label for="fb_message">Your Feedback</label>
                        <textarea id="fb_message" rows="5" placeholder="Tell us about your experience..."></textarea>
                        <div class="field-err" id="errMessage"></div>
                    </div>

                    <!-- Optional reference -->
                    <div class="field">
                        <label for="fb_reference">Service or Appointment <span class="opt">(optional)</span></label>
                        <input type="text" id="fb_reference" placeholder="e.g. Teeth Whitening, or your appointment date">
                    </div>

                    <!-- Public testimonial consent -->
                    <div class="field feedback-consent">
                        <label class="feedback-check">
                            <input type="checkbox" id="fb_public">
                            <span>I allow this feedback to be displayed publicly as a testimonial.</span>
                        </label>
                    </div>

                    <button type="submit" class="btn-primary" id="fbSubmitBtn">
                        Submit Feedback
                    </button>

                </form>

                <!-- ── Thank-you state (shown after a valid submit) ── -->
                <div id="feedbackThanks" class="feedback-thanks" style="display:none;">
                    <div class="modal-icon modal-icon-success"><i class="fa-solid fa-circle-check"></i></div>
                    <h2>Thank you!</h2>
                    <p>Your feedback has been received. We appreciate you taking the time to share your experience.</p>
                </div>

            </div>
        </main>

    </div>

    <script>
        document.getElementById('feedbackForm').addEventListener('submit', function (e) {
            e.preventDefault();

            // Clear previous errors
            document.querySelectorAll('.field-err').forEach(el => {
                el.style.display = 'none';
                el.textContent = '';
            });
            document.getElementById('feedbackAlert').style.display = 'none';

            const rating = document.querySelector('input[name="rating"]:checked');
            const message = document.getElementById('fb_message').value.trim();
            let ok = true;

            if (!rating) {
                const err = document.getElementById('errRating');
                err.textContent = 'Please select a rating.';
                err.style.display = 'block';
                ok = false;
            }
            if (!message) {
                const err = document.getElementById('errMessage');
                err.textContent = 'Please share a bit about your experience.';
                err.style.display = 'block';
                ok = false;
            }
            if (!ok) return;

            // TODO (Admin Side): POST this payload to the feedback API once
            // that endpoint and its database table exist, e.g.:
            //
            // const payload = {
            //     rating: rating.value,
            //     message: message,
            //     reference: document.getElementById('fb_reference').value.trim(),
            //     display_publicly: document.getElementById('fb_public').checked,
            // };
            // fetch('api/portal_feedback.php', {
            //     method: 'POST',
            //     headers: { 'Content-Type': 'application/json' },
            //     body: JSON.stringify(payload),
            // });
            //
            // For now this just shows the thank-you state client-side.

            document.getElementById('feedbackForm').style.display = 'none';
            document.getElementById('feedbackThanks').style.display = 'block';
        });
    </script>

</body>

</html>