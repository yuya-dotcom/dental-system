<!-- appointmentWeb-Landing.php  —  EssenciaSmile 2.0 Redesign -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EssenciaSmile – Your Journey to a Brighter Smile</title>

    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">

    <!-- Google Fonts: Cormorant Garamond (display) + Nunito Sans (body) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400;1,600&family=Nunito+Sans:opsz,wght@6..12,300;6..12,400;6..12,500;6..12,600;6..12,700;6..12,800&display=swap" rel="stylesheet">

    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <!-- Custom CSS -->
    <link rel="stylesheet" type="text/css" href="assets/css/appointmentPage.css">
    <link rel="stylesheet" type="text/css" href="assets/css/webNavbar.css">
    <link rel="stylesheet" type="text/css" href="assets/css/webFooter.css">
</head>

<body>

<?php include("partials/appointmentWeb-Navbar.php")?>


<!-- ════════════════════════════════════════════════════
     HERO
════════════════════════════════════════════════════ -->
<section id="hero" class="es-hero">

    <!-- Ambient glow orbs -->
    <div class="es-hero__orbs" aria-hidden="true">
        <div class="es-hero__orb es-hero__orb--1"></div>
        <div class="es-hero__orb es-hero__orb--2"></div>
        <div class="es-hero__orb es-hero__orb--3"></div>
    </div>

    <div class="container position-relative" style="z-index:2">
        <div class="row align-items-center gy-5">

            <!-- ── Left: Copy ── -->
            <div class="col-lg-6 col-xl-7">

                <div class="es-hero__badge">
                    <i class="fa-solid fa-tooth fa-xs"></i>
                    Trusted Dental Care
                </div>

                <h1 class="es-hero__title">
                    Your Journey to a<br>
                    Healthy, <em>Brighter<br>Smile</em> Starts Here
                </h1>

                <p class="es-hero__sub">
                    Easily book and manage your appointments with EssenciaSmile
                    while enjoying high-quality dental care for a brighter, healthier smile.
                </p>

                <div class="es-hero__ctas">
                    <a href="#services" class="es-btn es-btn--blue">
                        Book Appointment &nbsp;→
                    </a>
                    <a href="#about" class="es-btn es-btn--ghost">
                        Learn More
                    </a>
                </div>

                <div class="es-hero__stats">
                    <div class="es-stat">
                        <span class="es-stat__num">500+</span>
                        <span class="es-stat__label">Happy Patients</span>
                    </div>
                    <div class="es-stat">
                        <span class="es-stat__num">10+</span>
                        <span class="es-stat__label">Expert Dentists</span>
                    </div>
                    <div class="es-stat">
                        <span class="es-stat__num">5</span>
                        <span class="es-stat__label">Clinic Branches</span>
                    </div>
                </div>

            </div><!-- /left -->

            <!-- ── Right: Image frame ── -->
            <div class="col-lg-6 col-xl-5 d-none d-lg-flex justify-content-end">
                <div class="es-hero__img-wrap">
                    <div class="es-hero__img-frame">

                        <!-- Photo -->
                        <div class="es-hero__img" role="img" aria-label="Smiling dental patients"></div>

                        <!-- Rating badge -->
                        <div class="es-hero__img-badge">
                            <div>
                                <div class="badge-stars">
                                    <i class="fa-solid fa-star"></i>
                                    <i class="fa-solid fa-star"></i>
                                    <i class="fa-solid fa-star"></i>
                                    <i class="fa-solid fa-star"></i>
                                    <i class="fa-solid fa-star"></i>
                                </div>
                                <div class="badge-label">4.9 / 5.0 Patient Rating</div>
                            </div>
                        </div>

                        <!-- Years accent -->
                        <div class="es-hero__img-accent">
                            <div class="es-hero__img-accent-num">12+</div>
                            <div class="es-hero__img-accent-label">Years of<br>Excellence</div>
                        </div>

                    </div>
                </div>
            </div><!-- /right -->

        </div>
    </div>

    <!-- Wave transition to next section -->
    <div class="es-hero__wave" aria-hidden="true">
        <svg viewBox="0 0 1440 80" preserveAspectRatio="none" fill="#faf9f6" xmlns="http://www.w3.org/2000/svg">
            <path d="M0,80 C240,20 480,70 720,40 C960,10 1200,60 1440,20 L1440,80 Z"/>
        </svg>
    </div>

</section>


<!-- ════════════════════════════════════════════════════
     WHY CHOOSE US
════════════════════════════════════════════════════ -->
<section id="why-choose" class="es-why">
    <div class="container">

        <div class="es-section-header text-center">
            <span class="es-eyebrow">Why Choose Us</span>
            <h2>Transforming Lives, One<br>Beautiful Smile at a Time</h2>
            <p>Discover why patients choose EssenciaSmile for quality dental care, comfort,
               and convenient services designed around your needs.</p>
        </div>

        <div class="row g-4 mt-3">

            <div class="col-12 col-md-6 col-lg-3 d-flex">
                <div class="es-feature-card">
                    <div class="es-feature-card__icon">
                        <i class="fa-solid fa-tooth"></i>
                    </div>
                    <h5>High-Quality Dental Care</h5>
                    <p>Professional dental services delivered with care, comfort, and
                       attention to detail — because your smile matters.</p>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-3 d-flex">
                <div class="es-feature-card">
                    <div class="es-feature-card__icon">
                        <i class="fa-solid fa-calendar-days"></i>
                    </div>
                    <h5>Easy Appointment Booking</h5>
                    <p>Schedule your dental visit quickly and conveniently.
                       No long calls, no waiting — just a few clicks.</p>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-3 d-flex">
                <div class="es-feature-card">
                    <div class="es-feature-card__icon">
                        <i class="fa-solid fa-user-doctor"></i>
                    </div>
                    <h5>Experienced &amp; Caring Dentists</h5>
                    <p>Our skilled dental professionals are committed to providing
                       safe, gentle, and reliable treatments.</p>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-3 d-flex">
                <div class="es-feature-card">
                    <div class="es-feature-card__icon">
                        <i class="fa-solid fa-location-dot"></i>
                    </div>
                    <h5>Multiple Clinic Locations</h5>
                    <p>Visit us at any of our conveniently located branches and
                       receive the same quality care everywhere.</p>
                </div>
            </div>

        </div>
    </div>
</section>


<!-- ════════════════════════════════════════════════════
     ABOUT US
════════════════════════════════════════════════════ -->
<section id="about" class="es-about">
    <div class="container">
        <div class="row align-items-center g-5">

            <!-- Image side -->
            <div class="col-lg-5 es-about__img-col">
                <div class="es-about__img" role="img" aria-label="Modern dental treatment chair"></div>
                <div class="es-about__img-badge">
                    <div class="es-about__img-badge-num">12+</div>
                    <div class="es-about__img-badge-label">Years of<br>Excellence</div>
                </div>
            </div>

            <!-- Content side -->
            <div class="col-lg-7">
                <div class="es-about__content">
                    <span class="es-eyebrow">About Us</span>
                    <h2>At EssenciaSmile<br>Dental Center</h2>
                    <p>We believe that every smile deserves the highest level of care and attention.
                       Our clinic is dedicated to providing gentle, personalized dental services in a
                       comfortable and welcoming environment.</p>

                    <div class="es-about__features">
                        <div class="es-about__feat">
                            <span class="es-about__feat-icon">
                                <i class="fa-solid fa-heart fa-xs"></i>
                            </span>
                            Patient-Centered Care
                        </div>
                        <div class="es-about__feat">
                            <span class="es-about__feat-icon">
                                <i class="fa-solid fa-shield-halved fa-xs"></i>
                            </span>
                            Safe &amp; Clean Environment
                        </div>
                        <div class="es-about__feat">
                            <span class="es-about__feat-icon">
                                <i class="fa-solid fa-user fa-xs"></i>
                            </span>
                            Experienced &amp; Friendly Team
                        </div>
                        <div class="es-about__feat">
                            <span class="es-about__feat-icon">
                                <i class="fa-solid fa-face-smile fa-xs"></i>
                            </span>
                            Comfortable &amp; Relaxing Visits
                        </div>
                    </div>

                    <a href="#services" class="es-btn es-btn--teal">Explore Our Services &nbsp;→</a>
                </div>
            </div>

        </div>
    </div>
</section>


<!-- ════════════════════════════════════════════════════
     SERVICES   (wave top + dark section + wave bottom)
════════════════════════════════════════════════════ -->
<!-- Top wave: cream → dark teal -->
<span class="es-services-wave-top" aria-hidden="true">
    <svg viewBox="0 0 1440 70" preserveAspectRatio="none" fill="#061f1a" xmlns="http://www.w3.org/2000/svg">
        <path d="M0,0 C360,70 1080,0 1440,50 L1440,70 L0,70 Z"/>
    </svg>
</span>

<section id="services" class="es-services">
    <div class="container position-relative" style="z-index:1">

        <div class="es-section-header es-section-header--light text-center">
            <span class="es-eyebrow es-eyebrow--light">Our Services</span>
            <h2>Care Designed<br>for Your Smile</h2>
            <p>Explore our range of dental services designed to keep your smile healthy and confident.</p>
        </div>

        <div class="row g-4 mt-3">

            <div class="col-12 col-md-6 col-lg-4 d-flex">
                <div class="es-service-card">
                    <div class="es-service-card__icon"><i class="fa-solid fa-tooth"></i></div>
                    <h5>Routine Check-Up &amp; Cleaning</h5>
                    <p>Maintain healthy teeth and gums with regular check-ups and professional cleaning to prevent dental problems early.</p>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-4 d-flex">
                <div class="es-service-card">
                    <div class="es-service-card__icon"><i class="fa-solid fa-star"></i></div>
                    <h5>Teeth Whitening</h5>
                    <p>Brighten your smile with safe and effective whitening treatments that remove stains and restore natural shine.</p>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-4 d-flex">
                <div class="es-service-card">
                    <div class="es-service-card__icon"><i class="fa-solid fa-teeth"></i></div>
                    <h5>Braces and Orthodontics</h5>
                    <p>Straighten your teeth and improve your bite with customized orthodontic solutions for a more confident smile.</p>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-4 d-flex">
                <div class="es-service-card">
                    <div class="es-service-card__icon"><i class="fa-solid fa-hand-holding-medical"></i></div>
                    <h5>Tooth Extraction</h5>
                    <p>Safe and gentle removal of damaged or problematic teeth to protect your overall oral health.</p>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-4 d-flex">
                <div class="es-service-card">
                    <div class="es-service-card__icon"><i class="fa-solid fa-screwdriver-wrench"></i></div>
                    <h5>Dental Fillings &amp; Restorations</h5>
                    <p>Repair cavities and restore the strength and appearance of your teeth with durable, natural-looking materials.</p>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-4 d-flex">
                <div class="es-service-card">
                    <div class="es-service-card__icon"><i class="fa-solid fa-user-doctor"></i></div>
                    <h5>Oral Consultation</h5>
                    <p>Get expert advice and personalized treatment plans based on your dental needs and concerns.</p>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- Bottom wave: dark teal → cream -->
<span class="es-services-wave-bottom" aria-hidden="true">
    <svg viewBox="0 0 1440 70" preserveAspectRatio="none" fill="#061f1a" xmlns="http://www.w3.org/2000/svg">
        <path d="M0,20 C480,80 960,0 1440,60 L1440,0 L0,0 Z"/>
    </svg>
</span>


<!-- ════════════════════════════════════════════════════
     HOW IT WORKS
════════════════════════════════════════════════════ -->
<section id="how-it-works" class="es-how">
    <div class="container">

        <div class="es-section-header text-center">
            <span class="es-eyebrow">Process</span>
            <h2>How It Works</h2>
            <p>A simple five-step process to a brighter, healthier smile.</p>
        </div>

        <div class="es-steps">

            <div class="es-step">
                <div class="es-step__circle-wrap">
                    <div class="es-step__circle">
                        <i class="fa-solid fa-user-plus"></i>
                    </div>
                    <span class="es-step__num">1</span>
                </div>
                <h6>Create an Account</h6>
                <p>Register for a free patient account to get started and unlock online scheduling.</p>
            </div>

            <div class="es-step">
                <div class="es-step__circle-wrap">
                    <div class="es-step__circle">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <span class="es-step__num">2</span>
                </div>
                <h6>Schedule Appointment</h6>
                <p>Easily book your visit online at your convenience — no long calls, no waiting.</p>
            </div>

            <div class="es-step">
                <div class="es-step__circle-wrap">
                    <div class="es-step__circle">
                        <i class="fa-solid fa-comments"></i>
                    </div>
                    <span class="es-step__num">3</span>
                </div>
                <h6>Relax &amp; Consult</h6>
                <p>Meet our friendly team and discuss your dental goals and concerns.</p>
            </div>

            <div class="es-step">
                <div class="es-step__circle-wrap">
                    <div class="es-step__circle">
                        <i class="fa-solid fa-hand-holding-medical"></i>
                    </div>
                    <span class="es-step__num">4</span>
                </div>
                <h6>Personalized Treatment</h6>
                <p>Receive a customized care plan tailored to your unique needs.</p>
            </div>

            <div class="es-step">
                <div class="es-step__circle-wrap">
                    <div class="es-step__circle">
                        <i class="fa-solid fa-teeth-open"></i>
                    </div>
                    <span class="es-step__num">5</span>
                </div>
                <h6>Enjoy Your New Smile</h6>
                <p>Walk out with confidence and a radiant, healthy smile you'll love.</p>
            </div>

        </div>
    </div>
</section>


<!-- ════════════════════════════════════════════════════
     TESTIMONIALS
════════════════════════════════════════════════════ -->
<section id="testimonials" class="es-testimonials">
    <div class="container">

        <div class="es-section-header text-center">
            <span class="es-eyebrow">Testimonials</span>
            <h2>What Our Patients Say</h2>
            <p>Real stories from real patients. Discover why so many people trust us with their smiles.</p>
        </div>

        <div class="row g-4 mt-3">

            <div class="col-md-6 col-lg-4 d-flex">
                <div class="es-testimonial-card">
                    <span class="es-testimonial-card__quote" aria-hidden="true">&ldquo;</span>
                    <div class="es-testimonial-card__stars">
                        <i class="fas fa-star"></i><i class="fas fa-star"></i>
                        <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                    </div>
                    <p class="es-testimonial-card__text">
                        I've always been nervous about the dentist, but the team here made me feel completely
                        at ease. My procedures were painless, and the results are incredible. I can finally
                        smile with confidence again!
                    </p>
                    <div class="es-testimonial-card__author">
                        <img src="assets/images/carl.png" alt="John Carl Mendoza">
                        <div>
                            <p class="es-testimonial-card__author-name">John Carl Mendoza</p>
                            <p class="es-testimonial-card__author-role">Preventive Care Patient</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4 d-flex">
                <div class="es-testimonial-card">
                    <span class="es-testimonial-card__quote" aria-hidden="true">&ldquo;</span>
                    <div class="es-testimonial-card__stars">
                        <i class="fas fa-star"></i><i class="fas fa-star"></i>
                        <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                    </div>
                    <p class="es-testimonial-card__text">
                        The modern technology and caring staff are fantastic. My smile has been completely
                        transformed. They worked with me on a personalized plan that fit my busy schedule
                        perfectly. Highly recommended!
                    </p>
                    <div class="es-testimonial-card__author">
                        <img src="assets/images/chelo.png" alt="Chelo Jane Delos Reyes">
                        <div>
                            <p class="es-testimonial-card__author-name">Chelo Jane Delos Reyes</p>
                            <p class="es-testimonial-card__author-role">Cosmetic Dentistry Patient</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4 d-flex">
                <div class="es-testimonial-card">
                    <span class="es-testimonial-card__quote" aria-hidden="true">&ldquo;</span>
                    <div class="es-testimonial-card__stars">
                        <i class="fas fa-star"></i><i class="fas fa-star"></i>
                        <i class="fas fa-star"></i><i class="fas fa-star"></i>
                        <i class="fas fa-star-half-alt"></i>
                    </div>
                    <p class="es-testimonial-card__text">
                        From start to finish, the entire experience was professional and gentle. The clinic
                        is spotless and the reception team is wonderful. It was the most stress-free dental
                        visit I've ever had.
                    </p>
                    <div class="es-testimonial-card__author">
                        <img src="assets/images/ledona.png" alt="Marie Erica Ledona">
                        <div>
                            <p class="es-testimonial-card__author-name">Marie Erica Ledona</p>
                            <p class="es-testimonial-card__author-role">Routine Cleaning Patient</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>


<!-- ════════════════════════════════════════════════════
     CTA STRIP
════════════════════════════════════════════════════ -->
<section class="es-cta-strip">
    <div class="container es-cta-strip__inner">
        <h2>Ready for Your <em>Best Smile?</em></h2>
        <p>Join thousands of satisfied patients. Create your account today and schedule
           your first visit in minutes.</p>
        <a href="portal-login.php" class="es-btn es-btn--blue">
            Create Account Now &nbsp;→
        </a>
    </div>
</section>


<!-- Contact anchor for footer nav links -->
<div id="contact"></div>
<?php include("partials/appointmentWeb-Footer.php")?>

<!-- Vendors -->
<script src="assets/vendors/js/vendors.min.js"></script>
<!-- External JS — no inline scripts per project convention -->
<script src="assets/js/landingPage.js"></script>

</body>
</html>