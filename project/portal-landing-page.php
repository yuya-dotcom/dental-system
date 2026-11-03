<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EssenciaSmile</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <!-- Google Fonts: Cormorant Garamond (display) + Nunito Sans (body) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400;1,600&family=Nunito+Sans:opsz,wght@6..12,300;6..12,400;6..12,500;6..12,600;6..12,700;6..12,800&display=swap" rel="stylesheet">

    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/landing-page.css?v=3">



</head>

<body>
    <section class="navigation-bar">
        <a href="#landingpage-hero" class="nav-logo" aria-label="EssenciaSmile home">
            <img src="assets/images/EssenciaSmile-Original-Logo.svg" alt="EssenciaSmile" width="183" height="32">
        </a>
        <button class="nav-toggle" id="navToggle" aria-label="Toggle menu" aria-expanded="false">
            <span class="nav-toggle-bar"></span>
            <span class="nav-toggle-bar"></span>
            <span class="nav-toggle-bar"></span>
        </button>
        <ul id="navMenu">
            <li><a href="#landingpage-hero"><i class="fa-solid fa-house"></i> Home</a></li>
            <li><a href="#about-us"><i class="fa-solid fa-tooth"></i> About</a></li>
            <li><a href="#services"><i class="fa-solid fa-star"></i> Services</a></li>
            <li><a href="#footer"><i class="fa-solid fa-envelope"></i> Contacts</a></li>
            <li class="nav-mobile-cta">
                <a href="portal-login.php" class="btn btn-create-account-mobile rounded-pill">
                    Create an Account
                </a>
            </li>
        </ul>
        <a href="portal-login.php" class="btn btn-create-account rounded-3 px-3 py-2">
            Create an Account
        </a>
    </section>

    <section class="landingpage-hero" id="landingpage-hero">

        <h1> Your Journey to a Healthy, <span class="highlight-blue">Brighter Smile</span> Starts Here</h1>
        <p>Easily book and manage your appointments with EssenciaSmile while enjoying high-quality dental care for a brighter, healthier smile.</p>
        <a href="portal-login.php" class="btn btn-book-appointment rounded-3 px-3 py-2">
            Book an Appointment
        </a>
    </section>

    <section class="landingpage-whychooseus" id="why-choose-us">
        <div class="whychooseus-heading">
            <h1>Transforming Lives, <br> One Beautiful Smile at a Time</h1>
            <p>Discover why patients choose EssenciaSmile for quality dental care, comfort, and convenient services designed around your needs.</p>
        </div>

        <div class="row g-4 mt-3">
            <div class="col-12 col-md-6 col-lg-3 d-flex">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fa-solid fa-tooth" style="color: #1e87ff;"></i></div>
                    <h5>High Quality Care</h5>
                    <p>Professional dental services delivered with care, comfort, and attention to detail because your smile matters.</p>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-3 d-flex">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fa-solid fa-calendar-check" style="color: #1e87ff;"></i></div>
                    <h5>Easy Appointment Booking</h5>
                    <p>Schedule your dental visit quickly and conveniently. No long calls, no waiting just a few clicks.</p>
                </div>

            </div>
            <div class="col-12 col-md-6 col-lg-3 d-flex">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fa-solid fa-user-doctor" style="color: #1e87ff;"></i></div>
                    <h5>Experienced Dentists</h5>
                    <p>Our skilled dental professionals are committed to providing safe, gentle, and reliable treatments.</p>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-3 d-flex">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fa-solid fa-location-dot" style="color: #1e87ff;"></i></i></div>
                    <h5>Multiple Clinic Locations</h5>
                    <p>Visit us at any of our conveniently located branches and receive the same quality care everywhere.</p>
                </div>
            </div>

        </div>

    </section>

    <!-- ── About Us ── -->
    <section class="about-us" id="about-us">
        <div class="about-us-image">
            <img src="assets/images/procedure-essencia.jpg" alt="Dentist and assistant examining a patient's teeth at EssenciaSmile">
        </div>

        <div class="about-us-heading">
            <h1>At EssenciaSmile Dental Center</h1>
            <p>We believe that every smile deserves the highest level of care and attention. Our clinic is dedicated to providing gentle, personalized dental services in a comfortable and welcoming environment.</p>

            <div class="about-us-badges">
                <span class="badge-pill">Patient Centered Care</span>
                <span class="badge-pill">Safe and Clean Environment</span>
                <span class="badge-pill">Experienced &amp; Friendly Team</span>
            </div>
        </div>
    </section>

    <!-- ── Our Locations ──────────────────────────────────────────────── -->
    <section class="locations" id="locations">
        <div class="locations-heading">
            <h1>Visit Us at Our Clinic Chains</h1>
            <p>Find the EssenciaSmile branch nearest you and enjoy the same quality dental care at every location.</p>
        </div>

        <div class="row g-4 mt-3">
            <div class="col-12 col-md-6 col-lg-4 d-flex">
                <div class="location-card">
                    <div class="location-icon"><i class="fa-solid fa-location-dot"></i></div>
                    <h5>Novaliches</h5>
                    <!-- TODO: replace with the real Novaliches branch address -->
                    <p class="location-address"><i class="fa-solid fa-map-pin"></i> 108 Susano Road, Novaliches, Quezon City</p>
                    <p class="location-hours"><i class="fa-solid fa-clock"></i> Mon–Sat, 9:00 AM – 6:00 PM</p>
                    <!-- TODO: swap for a precise address/coordinates once available -->
                    <a href="https://www.google.com/maps/dir/?api=1&destination=EssenciaSmile%20Dental%20Center%2C%20Novaliches%2C%20Quezon%20City" target="_blank" rel="noopener" class="btn-directions">
                        <i class="fa-solid fa-diamond-turn-right"></i> Get Directions
                    </a>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-4 d-flex">
                <div class="location-card">
                    <div class="location-icon"><i class="fa-solid fa-location-dot"></i></div>
                    <h5>Mandaluyong</h5>
                    <!-- TODO: replace with the real Mandaluyong branch address -->
                    <p class="location-address"><i class="fa-solid fa-map-pin"></i> 565 Sgt. Bumatay St., Mandaluyong City</p>
                    <p class="location-hours"><i class="fa-solid fa-clock"></i> Mon–Sat, 9:00 AM – 6:00 PM</p>
                    <!-- TODO: swap for a precise address/coordinates once available -->
                    <a href="https://www.google.com/maps/dir/?api=1&destination=EssenciaSmile%20Dental%20Center%2C%20Mandaluyong%20City" target="_blank" rel="noopener" class="btn-directions">
                        <i class="fa-solid fa-diamond-turn-right"></i> Get Directions
                    </a>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-4 d-flex">
                <div class="location-card">
                    <div class="location-icon"><i class="fa-solid fa-location-dot"></i></div>
                    <h5>Caloocan</h5>
                    <!-- TODO: replace with the real Caloocan branch address -->
                    <p class="location-address"><i class="fa-solid fa-map-pin"></i> 132 Vista Verde Ave., Caloocan City</p>
                    <p class="location-hours"><i class="fa-solid fa-clock"></i> Mon–Sat, 9:00 AM – 6:00 PM</p>
                    <!-- TODO: swap for a precise address/coordinates once available -->
                    <a href="https://www.google.com/maps/dir/?api=1&destination=EssenciaSmile%20Dental%20Center%2C%20Caloocan%20City" target="_blank" rel="noopener" class="btn-directions">
                        <i class="fa-solid fa-diamond-turn-right"></i> Get Directions
                    </a>
                </div>
            </div>
        </div>

        <div class="locations-callout">
            <div class="locations-callout-icon"><i class="fa-solid fa-map-location-dot"></i></div>
            <div class="locations-callout-text">
                <h4>Find the Nearest Branch</h4>
                <p>Choose a location that's most convenient for you and book your appointment today.</p>
            </div>
            <div class="locations-callout-visual" aria-hidden="true">
                <i class="fa-solid fa-location-dot"></i>
                <i class="fa-solid fa-location-dot"></i>
                <i class="fa-solid fa-location-dot"></i>
            </div>
        </div>
    </section>

    <section class="services" id="services">
        <div class="services-heading">
            <h1>Care Designed For Your Smile</h1>
            <p>Explore our range of dental services designed to keep your smile healthy and confident.</p>
        </div>

        <div class="row g-4 mt-3">
            <div class="col-12 col-md-6 col-lg-4 d-flex">
                <div class="service-card">
                    <div class="service-icon"><i class="fa-solid fa-tooth" style="color: #1e87ff;"></i></div>
                    <h5>Routine Check-Up and Cleaning</h5>
                    <p>Maintain healthy teeth and gums with regular check-ups and professional cleaning to prevent dental problems early.</p>
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-4 d-flex">
                <div class="service-card">
                    <div class="service-icon"><i class="fa-solid fa-star" style="color: #1e87ff;"></i></div>
                    <h5>Teeth Whitening</h5>
                    <p>Brighten your smile with safe and effective whitening treatments that remove stains and restore natural shine.</p>
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-4 d-flex">
                <div class="service-card">
                    <div class="service-icon"><i class="fa-solid fa-teeth" style="color: #1e87ff;"></i></div>
                    <h5>Braces and Orthodontics</h5>
                    <p>Straighten your teeth and improve your bite with customized orthodontic solutions for a more confident smile.</p>
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-4 d-flex">
                <div class="service-card">
                    <div class="service-icon"><i class="fa-solid fa-wrench" style="color: #1e87ff;"></i></div>
                    <h5>Tooth Extraction</h5>
                    <p>Safe and gentle removal of damaged or problematic teeth to protect your overall oral health.</p>
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-4 d-flex">
                <div class="service-card">
                    <div class="service-icon"><i class="fa-solid fa-screwdriver-wrench" style="color: #1e87ff;"></i></div>
                    <h5>Dental Fillings & Restorations</h5>
                    <p>Repair cavities and restore the strength and appearance of your teeth with durable, natural-looking materials.</p>
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-4 d-flex">
                <div class="service-card">
                    <div class="service-icon"><i class="fa-solid fa-user-doctor" style="color: #1e87ff;"></i></div>
                    <h5>Oral Consultation</h5>
                    <p>Get expert advice and personalized treatment plans based on your dental needs and concerns.</p>
                </div>
            </div>
    </section>

    <section class="how-it-works" id="how-it-works">
        <div class="how-it-works-heading">
            <h1>How it works</h1>
            <p>A simple five-step process to a brighter, healthier smile.</p>
        </div>

        <div class="steps-row">
            <div class="step">
                <div class="step-icon"><i class="fa-solid fa-user-plus"></i></div>
                <h5>Create an Account</h5>
                <p>Register for a free patient account to get started and unlock online scheduling.</p>
            </div>

            <div class="step">
                <div class="step-icon"><i class="fa-solid fa-calendar-check"></i></div>
                <h5>Schedule Appointment</h5>
                <p>Easily book your visit online at your convenience no long calls, no waiting.</p>
            </div>

            <div class="step">
                <div class="step-icon"><i class="fa-solid fa-comments"></i></div>
                <h5>Relax and Consult</h5>
                <p>Meet our friendly team and discuss your dental goals and concerns.</p>
            </div>

            <div class="step">
                <div class="step-icon"><i class="fa-solid fa-tooth"></i></div>
                <h5>Personalized Treatment</h5>
                <p>Receive a customized care plan tailored to your unique needs.</p>
            </div>

            <div class="step">
                <div class="step-icon"><i class="fa-solid fa-table-cells"></i></div>
                <h5>Enjoy Your New Smile</h5>
                <p>Walk out with confidence and a radiant, healthy smile you'll love.</p>
            </div>
        </div>
    </section>

    <section class="testimonials" id="testimonials">
        <div class="testimonials-heading">
            <h1>What Our Patients Say</h1>
            <p>Real stories from real patients. Discover why so many people trust us with their smiles.</p>
        </div>

        <div class="row g-4 mt-3">
            <div class="col-12 col-md-6 col-lg-4 d-flex">
                <div class="testimonial-card">
                    <div class="stars">
                        <i class="fa-solid fa-star"></i>
                        <i class="fa-solid fa-star"></i>
                        <i class="fa-solid fa-star"></i>
                        <i class="fa-solid fa-star"></i>
                        <i class="fa-solid fa-star"></i>
                    </div>
                    <p>I've always been nervous about the dentist, but the team here made me feel completely at ease. My procedures were painless, and the results are incredible. I can finally smile with confidence again!</p>
                    <hr>
                    <div class="testimonial-author">
                        <img class="avatar" src="assets/images/carl.png" alt="Portrait of John Carl Mendoza" width="48" height="48" loading="lazy">
                        <div>
                            <h6>John Carl Mendoza</h6>
                            <span>Preventive Care Patient</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-4 d-flex">
                <div class="testimonial-card">
                    <div class="stars">
                        <i class="fa-solid fa-star"></i>
                        <i class="fa-solid fa-star"></i>
                        <i class="fa-solid fa-star"></i>
                        <i class="fa-solid fa-star"></i>
                        <i class="fa-solid fa-star"></i>
                    </div>
                    <p>The modern technology and caring staff are fantastic. My smile has been completely transformed. They worked with me on a personalized plan that fit my busy schedule perfectly. Highly recommended!</p>
                    <hr>
                    <div class="testimonial-author">
                        <img class="avatar" src="assets/images/chelo.png" alt="Portrait of Chelo Jane Delos Reyes" width="48" height="48" loading="lazy">
                        <div>
                            <h6>Chelo Jane Delos Reyes</h6>
                            <span>Cosmetic Dentistry Patient</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-4 d-flex">
                <div class="testimonial-card">
                    <div class="stars">
                        <i class="fa-solid fa-star"></i>
                        <i class="fa-solid fa-star"></i>
                        <i class="fa-solid fa-star"></i>
                        <i class="fa-solid fa-star"></i>
                        <i class="fa-solid fa-star"></i>
                    </div>
                    <p>From start to finish, the entire experience was professional and gentle. The clinic is spotless and the reception team is wonderful. It was the most stress-free dental visit I've ever had.</p>
                    <hr>
                    <div class="testimonial-author">
                        <img class="avatar" src="assets/images/ledona.png" alt="Portrait of Marie Erica Ledona" width="48" height="48" loading="lazy">
                        <div>
                            <h6>Marie Erica Ledona</h6>
                            <span>Routine Cleaning Patient</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="cta">
        <h1>Ready For Your Best Smile?</h1>
        <p>Join thousands of satisfied patients. Create your account today and schedule your first visit in minutes.</p>
        <a href="portal-login.php" class="btn btn-cta rounded-pill px-4 py-2">Book Your Appointment Now!</a>
    </section>

    <footer class="footer" id="footer">
        <div class="footer-top">
            <div class="footer-brand">
                <h3><i class="fa-solid fa-tooth"></i> Essencia<span class="highlight-green">Smile</span></h3>
                <p>All appointment bookings, treatment plans, and dental services offered on this platform are designed to give you safe, gentle, and reliable care every visit.</p>
                <a href="https://www.facebook.com/EssenciaSmile" class="social-icon"><i class="fa-brands fa-facebook-f"></i></a>
            </div>

            <div class="footer-links">
                <h6>QUICKLINKS</h6>
                <ul>
                    <li><a href="#landingpage-hero">Home</a></li>
                    <li><a href="#about-us">About</a></li>
                    <li><a href="#services">Services</a></li>
                    <li><a href="#locations">Locations</a></li>
                    <li><a href="#how-it-works">How it works</a></li>
                    <li><a href="#testimonials">Testimonials</a></li>
                </ul>
            </div>

            <div class="footer-info">
                <h6>INFORMATION</h6>
                <ul>
                    <li><a href="contact-us-page.php">Contact Us</a></li>
                    <li><a href="portal-terms.php">Terms and Conditions</a></li>
                </ul>
            </div>
        </div>

        <hr>

        <div class="footer-bottom">
            <p>&copy; 2026 EssenciaSmile Dental Center</p>
        </div>
    </footer>

    <!-- ── Back to Top button ───────────────────────────────────────────── -->
    <button type="button" id="backToTop" class="back-to-top" aria-label="Back to top">
        <i class="fa-solid fa-arrow-up"></i>
    </button>

    <!-- Vendors -->
    <script src="assets/vendors/js/vendors.min.js"></script>
    <script>
        const navToggle = document.getElementById('navToggle');
        const navMenu = document.getElementById('navMenu');
        navToggle.addEventListener('click', () => {
            const isOpen = navMenu.classList.toggle('nav-open');
            navToggle.setAttribute('aria-expanded', isOpen);
        });

        // ── Back to Top ──────────────────────────────────────────────────
        const backToTop = document.getElementById('backToTop');

        window.addEventListener('scroll', () => {
            backToTop.classList.toggle('show', window.scrollY > 400);
        });

        backToTop.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    </script>
</body>

</html>