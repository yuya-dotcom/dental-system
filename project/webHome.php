<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="">
    <meta name="keyword" content="">
    <meta name="author" content="theme_ocean">
    <!--! The above 6 meta tags *must* come first in the head; any other head content must come *after* these tags !-->
    <!--! BEGIN: Apps Title-->
    <title> EssenciaSmile</title>
    <!--! END:  Apps Title-->
    <!--! BEGIN: Favicon-->
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <!--! END: Favicon-->
    <!--! BEGIN: Bootstrap CSS-->
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <!--! END: Bootstrap CSS-->
    <!--! BEGIN: Vendors CSS-->
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/daterangepicker.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/jquery-jvectormap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/select2.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/select2-theme.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/jquery.time-to.min.css">
    <!--! END: Vendors CSS-->
    <!--! BEGIN: Custom CSS-->
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" href="assets/css/webNavbar.css">
    <link rel="stylesheet" href="assets/css/webHome.css">
    <!--! END: Custom CSS-->
</head>

<body>
    <!--! ================================================================ !-->
    <!--! [Start] Navigation Manu !-->
    <!--! ================================================================ !-->
    <?php include("partials/webNavbar.php") ?>
    <!--! ================================================================ !-->
    <!--! [End]  Navigation Manu !-->

    <main>
        <section id="hero" class="hero section">

        <img src="assets/images/hero-bg-abstract.jpg" alt="" data-aos="fade-in" class="">

        <div class="container">
            <div class="row justify-content-center" data-aos="zoom-out">
                <div class="col-xl-7 col-lg-9 text-center">
                    <h1>Bright Smile Starts Here</h1>
                    <p>Experience Exceptional Dental Care for Brighter Smile Today. Easily set and manage appointments with EssenciaSmile.</p>
                </div>
            </div>
        <div class="d-flex text-center justify-content-center mt-4" data-aos="zoom-out" data-aos-delay="100">
            <button type="button" class="btn btn-primary justify-center" data-bs-toggle="modal" data-bs-target="#patientTypeModal">
            Get Started    
            </button>

        </div>

        <div class="row gy-4 mt-5">
            <div class="col-md-6 col-lg-3" data-aos="zoom-out" data-aos-delay="100">
                <div class="icon-box">
                    <div class="icon"><i class="fa-solid fa-tooth"></i></div>
                        <h4 class="title"><a href="">Quality Dental Care</a></h4>
                        <p class="description">Professional dental services delivered with care, comfort, and attention to detail because your smile matters.</p>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3" data-aos="zoom-out" data-aos-delay="200">
                    <div class="icon-box">
                        <div class="icon"><i class="fa-solid fa-calendar-days"></i></div>
                            <h4 class="title"><a href="">Easy Appointment Booking</a></h4>
                            <p class="description">Schedule your dental visit quickly and conveniently. No long calls, no waiting just a few clicks.</p>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3" data-aos="zoom-out" data-aos-delay="300">
                        <div class="icon-box">
                            <div class="icon"><i class="fa-solid fa-user-doctor"></i></div>
                                <h4 class="title"><a href="">Experienced & Caring Dentists</a></h4>
                                <p class="description">Our skilled dental professionals are committed to providing safe, gentle, and reliable treatments.</p>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-3" data-aos="zoom-out" data-aos-delay="400">
                            <div class="icon-box">
                                <div class="icon"><i class="fa-solid fa-location-dot"></i></div>
                                    <h4 class="title"><a href="">Multiple Clinic Locations</a></h4>
                                    <p class="description">Visit us at any of our conveniently located branches and receive the same quality care everywhere.</p>
                                </div>
                            </div>
                        </div>
                    </div>
        </section>
    </main>

    <!-- New and Old Patient Modal -->
    <div class="modal fade" id="patientTypeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title"> Choose Patient type</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <!-- New Patient Button -->
                <button onclick="goWithLoading('webNewPatient.php')" class="patient-button btn w-100 d-flex align-items-center justify-content-start mb-3 p-4 rounded-3 ">

                <div class="icon-wrapper me-4">
                    <div class="icon-circle flex-shrink-0 me-3">
                        <i class="feather-user"></i>
                    </div>
                </div>

                <div class="text-start">
                    <div class="dark fw-semibold fs-5 text-uppercase">New Patient</div>
                    <small class="text-muted text-uppercase">First time with us?</small>
                </div>

                </button>

                <!-- Returning Patient Button -->
                <button class="patient-button btn w-100 d-flex align-items-center justify-content-start mb-3 p-4 rounded-3 ">

                <div class="icon-wrapper me-4">
                    <div class="icon-circle flex-shrink-0 me-3">
                        <i class="feather-users"></i>
                    </div>
                </div>

                <div class="text-start">
                    <div class="dark fw-semibold fs-5 text-uppercase">Returning Patient</div>
                    <small class="text-muted text-uppercase">Welcome Back!</small>
                </div>

                </button>

            </div>
            </div>
        </div>
    </div>

    <div id="pageLoader" class="loader-overlay d-none">
        <div class="spinner-border text-primary" style="width:3rem;height:3rem;"></div>
    </div>


    <!--! ================================================================ !-->
    <!--! [End] Main Content !-->
    <!--! ================================================================ !-->

    
    <!--! ================================================================ !-->
    <!--! Footer Script !-->
    <!--! ================================================================ !-->
    <!--! BEGIN: Vendors JS !-->
    <script src="assets/vendors/js/vendors.min.js"></script>
    <!-- vendors.min.js {always must need to be top} -->
    <script src="assets/vendors/js/daterangepicker.min.js"></script>
    <script src="assets/vendors/js/apexcharts.min.js"></script>
    <script src="assets/vendors/js/circle-progress.min.js"></script>
    <!--! END: Vendors JS !-->
    <!--! BEGIN: Apps Init  !-->
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/dashboard-init.min.js"></script>
    <!--! END: Apps Init !-->

    <!--! BEGIN: Custom JS  !-->
    <script src="assets/js/webHome.js"></script>
    <!--! END: Custom JS !-->

</body>
</html>