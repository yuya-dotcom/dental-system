<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EssenciaSmile</title>

    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/appointmentPage.css">
    <link rel="stylesheet" href="assets/css/webFooeter.css">

    <style>
        /* ── Smooth scroll (CSS layer) ── */
        html { scroll-behavior: smooth; }

        /* ── Offset for sticky navbar so anchor targets aren't hidden under it ── */
        :target { scroll-margin-top: 80px; }

        /* ── How It Works: 5-column grid on large screens ── */
        .steps-container {
            --step-cols: 5;
        }
        @media (max-width: 991px) {
            .steps-container { --step-cols: 2; }
        }
        @media (max-width: 575px) {
            .steps-container { --step-cols: 1; }
        }

        /* ── Step badge ── */
        .step-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--accent-color, #2487ce);
            color: #fff;
            font-size: .78rem;
            font-weight: 700;
            position: absolute;
            top: 14px;
            right: 14px;
        }

        /* ── Step 1 accent (new) ── */
        .step-item.step-new {
            border: 2px solid #2487ce !important;
        }
        .step-item.step-new .step-icon-wrapper {
            background: #2487ce !important;
            color: #fff !important;
        }

        /* ── Navbar link color fix (white bg navbar) ── */
        .navbar a {
            color: #444;
            transition: color .2s;
        }
        .navbar a:hover { color: #2487ce; }
    </style>
</head>

<body>
    <!-- ============================================================ -->
    <!-- Navbar                                                        -->
    <!-- ============================================================ -->
    <nav class="navbar d-flex align-items-center sticky-top shadow bg-white p-4">
        <div class="flex-shrink-0 ms-5">
            <a href="appointmentWeb-Landing.php">
                <img src="assets/images/Essencia-full@3x.png" alt="EssenciaSmile Logo">
            </a>
        </div>
        <div class="d-flex flex-grow-1 ms-3 justify-content-end align-items-center">
            <a href="#hero"         class="fs-6 fw-normal px-2">Home</a>
            <a href="#about"        class="fs-6 fw-normal px-2">About</a>
            <a href="#services"     class="fs-6 fw-normal px-2">Services</a>
            <a href="#contact"      class="fs-6 fw-normal px-2 me-4">Contacts</a>
            <a href="portal-login.php" class="btn btn-primary me-5" role="button">Create Account Now</a>
        </div>
    </nav>

    <!-- ============================================================ -->
    <!-- Hero                                                          -->
    <!-- ============================================================ -->
    <section id="hero" class="hero">
        <div class="hero-content">
            <h1 class="mt-3 text-primary">
                Your Journey to a Healthy,<br>Brighter Smile Starts Here
            </h1>
            <p class="fs-6 text-secondary">
                Easily book and manage your appointments with EssenciaSmile<br>
                while enjoying high-quality dental care for a brighter, healthier smile.
            </p>
            <a href="portal-login.php" class="btn btn-primary p-3 mt-2 mb-1">Create an appointment →</a>
        </div>
    </section>

    <!-- ============================================================ -->
    <!-- Why Choose Us                                                 -->
    <!-- ============================================================ -->
    <section id="why-choose" class="why-choose-us p-5 mt-4">
        <h2 class="text-center text-primary fw-bold">Transforming Lives, One Beautiful Smile at a Time</h2>
        <p class="text-center fs-6 pb-4 mb-4">
            Discover why patients choose EssenciaSmile for quality dental care, comfort, and
            convenient services designed around your needs.
        </p>
        <div class="container">
            <div class="row gy-4">
                <div class="col-12 col-md-6 col-lg-3 d-flex">
                    <div class="icon-box rounded-3">
                        <div class="icon"><i class="fa-solid fa-tooth text-primary pb-3 fs-4"></i></div>
                        <h5 class="title mb-2 fs-5">High-Quality Dental Care</h5>
                        <p class="text-muted description fs-6">Professional dental services delivered with care, comfort, and attention to detail because your smile matters.</p>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3 d-flex">
                    <div class="icon-box rounded-3">
                        <div class="icon"><i class="fa-solid fa-calendar-days text-primary pb-3 fs-4"></i></div>
                        <h5 class="title mb-2 fs-5">Easy Appointment Booking</h5>
                        <p class="text-muted description fs-6">Schedule your dental visit quickly and conveniently. No long calls, no waiting — just a few clicks.</p>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3 d-flex">
                    <div class="icon-box rounded-3">
                        <div class="icon"><i class="fa-solid fa-user-doctor text-primary pb-3 fs-4"></i></div>
                        <h5 class="title mb-2 fs-5">Experienced & Caring Dentists</h5>
                        <p class="text-muted description fs-6">Our skilled dental professionals are committed to providing safe, gentle, and reliable treatments.</p>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3 d-flex">
                    <div class="icon-box rounded-3">
                        <div class="icon"><i class="fa-solid fa-location-dot text-primary pb-3 fs-4"></i></div>
                        <h5 class="title mb-2 fs-5">Multiple Clinic Locations</h5>
                        <p class="text-muted description fs-6">Visit us at any of our conveniently located branches and receive the same quality care everywhere.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================ -->
    <!-- About Us                                                      -->
    <!-- ============================================================ -->
    <section id="about" class="about-us">
        <div class="about-us-container rounded-4 overflow-hidden">
            <div class="row align-items-stretch g-0">
                <!-- LEFT: IMAGE -->
                <div class="col-md-6 d-flex">
                    <div class="about-img"></div>
                </div>
                <!-- RIGHT: CONTENT -->
                <div class="col-lg-6 p-5">
                    <h3 class="fw-bold mb-3 fs-3">At EssenciaSmile Dental Center</h3>
                    <p class="text-muted mb-4 fs-6">
                        We believe that every smile deserves the highest level of care and attention.
                        Our clinic is dedicated to providing gentle, personalized dental services in a comfortable
                        and welcoming environment.
                    </p>
                    <div class="row">
                        <div class="col-6 mb-3 d-flex align-items-center gap-2">
                            <i class="fa-solid fa-heart text-primary fs-5"></i>
                            <span class="fs-6">Patient-Centered Care</span>
                        </div>
                        <div class="col-6 mb-3 d-flex align-items-center gap-2">
                            <i class="fa-solid fa-shield-halved text-primary fs-5"></i>
                            <span class="fs-6">Safe & Clean Environment</span>
                        </div>
                        <div class="col-6 mb-3 d-flex align-items-center gap-2">
                            <i class="fa-solid fa-user text-primary fs-5"></i>
                            <span class="fs-6">Experienced & Friendly Team</span>
                        </div>
                        <div class="col-6 mb-3 d-flex align-items-center gap-2">
                            <i class="fa-solid fa-face-smile text-primary fs-5"></i>
                            <span class="fs-6">Comfortable & Relaxing Visits</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================ -->
    <!-- Services                                                      -->
    <!-- ============================================================ -->
    <section id="services" class="services p-5">
        <h1 class="text-center text-primary fw-bold">Care Designed for Your Smile</h1>
        <p class="text-center fs-6 mb-5">Explore our range of dental services designed to keep your smile healthy and confident.</p>

        <div class="container">
            <div class="row gy-4">
                <div class="col-12 col-md-6 col-lg-4 d-flex">
                    <div class="service-box rounded-3">
                        <div class="icon d-flex align-items-center gap-2">
                            <i class="fa-solid fa-tooth text-primary fs-4"></i>
                            <h4 class="mb-0">Routine Check-Up & Cleaning</h4>
                        </div>
                        <p class="text-muted fs-6 mt-3">Maintain healthy teeth and gums with regular check-ups and professional cleaning to prevent dental problems early.</p>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-4 d-flex">
                    <div class="service-box rounded-3">
                        <div class="icon d-flex align-items-center gap-2">
                            <i class="fa-solid fa-star text-primary fs-4"></i>
                            <h4 class="mb-0">Teeth Whitening</h4>
                        </div>
                        <p class="text-muted fs-6 mt-3">Brighten your smile with safe and effective whitening treatments that remove stains and restore natural shine.</p>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-4 d-flex">
                    <div class="service-box rounded-3">
                        <div class="icon d-flex align-items-center gap-2">
                            <i class="fa-solid fa-teeth text-primary fs-4"></i>
                            <h4 class="mb-0">Braces and Orthodontics</h4>
                        </div>
                        <p class="text-muted fs-6 mt-3">Straighten your teeth and improve your bite with customized orthodontic solutions for a more confident smile.</p>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-4 d-flex">
                    <div class="service-box rounded-3">
                        <div class="icon d-flex align-items-center gap-2">
                            <i class="fa-solid fa-hand-holding-medical text-primary fs-4"></i>
                            <h4 class="mb-0">Tooth Extraction</h4>
                        </div>
                        <p class="text-muted fs-6 mt-3">Safe and gentle removal of damaged or problematic teeth to protect your overall oral health.</p>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-4 d-flex">
                    <div class="service-box rounded-3">
                        <div class="icon d-flex align-items-center gap-2">
                            <i class="fa-solid fa-screwdriver-wrench text-primary fs-4"></i>
                            <h4 class="mb-0">Dental Fillings & Restorations</h4>
                        </div>
                        <p class="text-muted fs-6 mt-3">Repair cavities and restore the strength and appearance of your teeth with durable, natural-looking materials.</p>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-4 d-flex">
                    <div class="service-box rounded-3">
                        <div class="icon d-flex align-items-center gap-2">
                            <i class="fa-solid fa-user-doctor text-primary fs-4"></i>
                            <h4 class="mb-0">Oral Consultation</h4>
                        </div>
                        <p class="text-muted fs-6 mt-3">Get expert advice and personalized treatment plans based on your dental needs and concerns.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================ -->
    <!-- How It Works  (now 5 steps — Step 1 added)                   -->
    <!-- ============================================================ -->
    <section id="how-it-works" class="how-it-works py-5">
        <div class="container">
            <div class="row text-center mb-5">
                <div class="col-12">
                    <h1 class="text-primary fw-bold">How It Works</h1>
                    <p class="text-center fs-6">A simple five-step process to a brighter, healthier smile.</p>
                </div>
            </div>

            <div class="row g-4 justify-content-center steps-container">

                <!-- ── STEP 1 (NEW) — Create an Account ── -->
                <div class="col-sm-6 col-md-4 col-lg step-item-wrapper">
                    <div class="step-item step-new card h-100 text-center border-0 p-4 shadow-sm position-relative">
                        <span class="step-badge">1</span>
                        <div class="step-icon-wrapper rounded-circle mx-auto mb-4 d-flex align-items-center justify-content-center">
                            <i class="fas fa-user-plus fa-2x"></i>
                        </div>
                        <div class="card-body">
                            <h5 class="card-title fw-semibold text-dark mb-2">Create an Account</h5>
                            <p class="card-text text-muted fs-6">
                                Register for a free patient account to get started and unlock online scheduling.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- ── STEP 2 — Schedule Appointment (was Step 1) ── -->
                <div class="col-sm-6 col-md-4 col-lg step-item-wrapper">
                    <div class="step-item card h-100 text-center border-0 p-4 shadow-sm position-relative">
                        <span class="step-badge">2</span>
                        <div class="step-icon-wrapper rounded-circle bg-primary-subtle text-primary mx-auto mb-4 d-flex align-items-center justify-content-center">
                            <i class="fas fa-calendar-check fa-2x"></i>
                        </div>
                        <div class="card-body">
                            <h5 class="card-title fw-semibold text-dark mb-2">Schedule Appointment</h5>
                            <p class="card-text text-muted fs-6">Easily book your visit online at your convenience — no long calls, no waiting.</p>
                        </div>
                    </div>
                </div>

                <!-- ── STEP 3 — Relax & Consult (was Step 2) ── -->
                <div class="col-sm-6 col-md-4 col-lg step-item-wrapper">
                    <div class="step-item card h-100 text-center border-0 p-4 shadow-sm position-relative">
                        <span class="step-badge">3</span>
                        <div class="step-icon-wrapper rounded-circle bg-primary-subtle text-primary mx-auto mb-4 d-flex align-items-center justify-content-center">
                            <i class="fas fa-comments fa-2x"></i>
                        </div>
                        <div class="card-body">
                            <h5 class="card-title fw-semibold text-dark mb-2">Relax &amp; Consult</h5>
                            <p class="card-text text-muted fs-6">Meet our friendly team and discuss your dental goals and concerns.</p>
                        </div>
                    </div>
                </div>

                <!-- ── STEP 4 — Personalized Treatment (was Step 3) ── -->
                <div class="col-sm-6 col-md-4 col-lg step-item-wrapper">
                    <div class="step-item card h-100 text-center border-0 p-4 shadow-sm position-relative">
                        <span class="step-badge">4</span>
                        <div class="step-icon-wrapper rounded-circle bg-primary-subtle text-primary mx-auto mb-4 d-flex align-items-center justify-content-center">
                            <i class="fas fa-hand-holding-medical fa-2x"></i>
                        </div>
                        <div class="card-body">
                            <h5 class="card-title fw-semibold text-dark mb-2">Personalized Treatment</h5>
                            <p class="card-text text-muted fs-6">Receive a customized care plan tailored to your unique needs.</p>
                        </div>
                    </div>
                </div>

                <!-- ── STEP 5 — Enjoy Your New Smile (was Step 4) ── -->
                <div class="col-sm-6 col-md-4 col-lg step-item-wrapper">
                    <div class="step-item card h-100 text-center border-0 p-4 shadow-sm position-relative">
                        <span class="step-badge">5</span>
                        <div class="step-icon-wrapper rounded-circle bg-primary-subtle text-primary mx-auto mb-4 d-flex align-items-center justify-content-center">
                            <i class="fas fa-teeth-open fa-2x"></i>
                        </div>
                        <div class="card-body">
                            <h5 class="card-title fw-semibold text-dark mb-2">Enjoy Your New Smile</h5>
                            <p class="card-text text-muted fs-6">Walk out with confidence and a radiant, healthy smile you'll love.</p>
                        </div>
                    </div>
                </div>

            </div><!-- /row -->
        </div>
    </section>

    <!-- ============================================================ -->
    <!-- Testimonials                                                  -->
    <!-- ============================================================ -->
    <section id="testimonials" class="testimonials py-5 bg-light">
        <div class="container">
            <div class="row text-center mb-5">
                <div class="col-12 col-md-8 mx-auto">
                    <h1 class="fw-bold text-dark">What Our Patients Say</h1>
                    <p class="text-center fs-6">Real stories from real patients. Discover why so many people trust us with their smiles.</p>
                </div>
            </div>
            <div class="row g-4 justify-content-center">

                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 testimonial-card border-0 p-4 shadow-sm">
                        <div class="card-body">
                            <div class="rating mb-3 text-warning">
                                <i class="fas fa-star"></i><i class="fas fa-star"></i>
                                <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                            </div>
                            <p class="card-text text-muted fs-6 mb-4">
                                "I've always been nervous about the dentist, but the team here made me feel completely at ease.
                                My procedures were painless, and the results are incredible. I can finally smile with confidence again!"
                            </p>
                            <div class="d-flex align-items-center mt-auto">
                                <img src="../project/assets/images/carl.png" alt="Patient Avatar" class="rounded-circle me-3" width="60" height="60">
                                <div>
                                    <h6 class="card-title fw-semibold text-dark mb-0">John Carl Mendoza</h6>
                                    <p class="card-text text-muted mb-0 fs-7">Preventive Care Patient</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 testimonial-card border-0 p-4 shadow-sm">
                        <div class="card-body">
                            <div class="rating mb-3 text-warning">
                                <i class="fas fa-star"></i><i class="fas fa-star"></i>
                                <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                            </div>
                            <p class="card-text text-muted fs-6 mb-4">
                                "The modern technology and caring staff are fantastic. My smile has been completely transformed.
                                They worked with me on a personalized plan that fit my busy schedule perfectly. Highly recommended!"
                            </p>
                            <div class="d-flex align-items-center mt-auto">
                                <img src="../project/assets/images/chelo.png" alt="Patient Avatar" class="rounded-circle me-3" width="60" height="60">
                                <div>
                                    <h6 class="card-title fw-semibold text-dark mb-0">Chelo Jane Delos Reyes</h6>
                                    <p class="card-text text-muted mb-0 fs-7">Cosmetic Dentistry Patient</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 testimonial-card border-0 p-4 shadow-sm">
                        <div class="card-body">
                            <div class="rating mb-3 text-warning">
                                <i class="fas fa-star"></i><i class="fas fa-star"></i>
                                <i class="fas fa-star"></i><i class="fas fa-star"></i>
                                <i class="fas fa-star-half-alt"></i>
                            </div>
                            <p class="card-text text-muted fs-6 mb-4">
                                "From start to finish, the entire experience was professional and gentle. The clinic is spotless
                                and the reception team is wonderful. It was the most stress-free dental visit I've ever had."
                            </p>
                            <div class="d-flex align-items-center mt-auto">
                                <img src="../project/assets/images/ledona.png" alt="Patient Avatar" class="rounded-circle me-3" width="60" height="60">
                                <div>
                                    <h6 class="card-title fw-semibold text-dark mb-0">Marie Erica Ledona</h6>
                                    <p class="card-text text-muted mb-0 fs-7">Routine Cleaning Patient</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- ============================================================ -->
    <!-- Footer                                                        -->
    <!-- ============================================================ -->
    <!-- Anchor target for "Contact" nav link -->
    <div id="contact"></div>
    <?php include("partials/appointmentWeb-Footer copy.php") ?>

    <!-- Vendors JS -->
    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/vendors/js/daterangepicker.min.js"></script>
    <script src="assets/vendors/js/apexcharts.min.js"></script>
    <script src="assets/vendors/js/circle-progress.min.js"></script>

    <script>
    // ── Smooth Scroll (JS layer — handles any #hash link) ──────────────
    // Overrides default jump behaviour and adds easing for older browsers
    // that don't support CSS scroll-behavior.
    document.querySelectorAll('a[href^="#"]').forEach(link => {
        link.addEventListener('click', function (e) {
            const targetId = this.getAttribute('href').slice(1);
            if (!targetId) return;
            const target = document.getElementById(targetId);
            if (!target) return;
            e.preventDefault();
            const navbarH = document.querySelector('.navbar')?.offsetHeight || 0;
            const top = target.getBoundingClientRect().top + window.scrollY - navbarH - 8;
            window.scrollTo({ top, behavior: 'smooth' });
        });
    });
    </script>
</body>
</html>