<?php
//portal-login.php
session_start();
require_once __DIR__ . '/portal-session-guard.php';
if (isset($_SESSION['portal_account_id'])) {
    if (portal_session_expired() === null) {
        header("Location: portal-dashboard.php");
        exit;
    }
    portal_destroy_session();
    session_start();
}

$API_PATH = 'api/portal_auth.php';

// Browser-safe Supabase config: URL + publishable key only (never the secret key)
$_secrets = require __DIR__ . '/config/secrets.php';
$SUPABASE_PUBLIC_URL = $_secrets['supabase_url'];
$SUPABASE_PUBLISHABLE_KEY = $_secrets['supabase_publishable_key'] ?? '';
unset($_secrets);
if (!str_starts_with($SUPABASE_PUBLISHABLE_KEY, 'sb_publishable_')) {
    $SUPABASE_PUBLISHABLE_KEY = '';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EssenciaSmile | Patient Portal</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link href="assets/fonts/sora/sora-300-800.css" rel="stylesheet"><!-- Sora, self-hosted (was Google Fonts) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <link rel="stylesheet" href="assets/css/portal-login.css?v=7">
    <link rel="stylesheet" href="assets/css/portalLogin-navbar.css?v=4">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=2">
    <link rel="stylesheet" href="assets/css/es-swal.css?v=4">
    <script>
        // ── Step navigation ───────────────────────────────────────────────────
        let currentRegStep = 1;

        function regGoToStep(n) {
            [1, 2].forEach(i => {
                document.getElementById('regStep' + i).classList.toggle('active', i === n);
                const prog = document.getElementById('prog' + i);
                prog.classList.toggle('active', i <= n);
                prog.classList.toggle('completed', i < n);
                const line = document.getElementById('progLine' + i);
                if (line) line.classList.toggle('filled', i < n);
            });
            currentRegStep = n;
            regScrollToTop();
            if (n === 2 && typeof initProvinces === 'function') initProvinces();
        }

        // Bring the step heading, progress bar and first fields into view.
        // Desktop: .right-panel is the scroll container (left hero stays fixed);
        // smaller screens: the page itself scrolls.
        function regScrollToTop() {
            const panel = document.querySelector('.right-panel');
            if (panel && panel.scrollHeight > panel.clientHeight) {
                panel.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }
            const card = document.querySelector('.auth-card');
            if (!card) return;
            const top = card.getBoundingClientRect().top + window.scrollY - 12;
            if (window.scrollY > top) window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
        }

        function regNextStep(from) {
            if (typeof clearErrors === 'function') clearErrors();
            if (!regValidateStep(from)) return;
            regGoToStep(from + 1);
        }

        // Returns true when the step's fields are valid; shows inline errors otherwise.
        function regValidateStep(from) {
            if (from === 1) {
                const firstName = document.getElementById('reg_firstName').value.trim();
                const lastName = document.getElementById('reg_lastName').value.trim();
                const birthdate = document.getElementById('reg_birthdate').value;
                const gender = document.getElementById('reg_gender').value;
                let ok = true;
                if (!firstName) {
                    if (typeof showErr === 'function') showErr('errRegFirst', 'First name is required.');
                    ok = false;
                }
                if (!lastName) {
                    if (typeof showErr === 'function') showErr('errRegLast', 'Last name is required.');
                    ok = false;
                }
                if (!birthdate) {
                    if (typeof showErr === 'function') showErr('errRegBirth', 'Birthdate is required.');
                    ok = false;
                }
                if (!gender) {
                    if (typeof showErr === 'function') showErr('errRegGender', 'Please select a gender.');
                    ok = false;
                }
                if (!ok) return false;
            }
            if (from === 2) {
                const contact = document.getElementById('reg_contact').value.trim();
                const email = document.getElementById('reg_email').value.trim();
                const address = document.getElementById('reg_address').value.trim();
                const province = document.getElementById('reg_province').value;
                const city = document.getElementById('reg_city').value;
                const password = document.getElementById('reg_password').value;
                const confirm = document.getElementById('reg_confirm').value;
                let ok = true;
                if (!contact || !/^9\d{9}$/.test(contact)) {
                    if (typeof showErr === 'function') showErr('errRegContact', 'Enter a valid 10-digit PH number starting with 9.');
                    ok = false;
                }
                if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    if (typeof showErr === 'function') showErr('errRegEmail', 'Enter a valid email address.');
                    ok = false;
                }
                if (!address) {
                    if (typeof showErr === 'function') showErr('errRegAddress', 'Home address is required.');
                    ok = false;
                }
                if (!province) {
                    if (typeof showErr === 'function') showErr('errRegProvince', 'Please select a province.');
                    ok = false;
                }
                if (!city) {
                    if (typeof showErr === 'function') showErr('errRegCity', 'Please select a city.');
                    ok = false;
                }
                if (password.length < 8) {
                    if (typeof showErr === 'function') showErr('errRegPass', 'Password must be 8+ characters.');
                    ok = false;
                }
                if (password !== confirm) {
                    if (typeof showErr === 'function') showErr('errRegConfirm', 'Passwords do not match. Please re-enter your password and try again.');
                    ok = false;
                }
                if (!ok) return false;
            }
            return true;
        }

        function regPrevStep(from) {
            regGoToStep(from - 1);
        }

        // ── Tab switch ────────────────────────────────────────────────────────
        function switchTab(tab) {
            const isLogin = tab === 'login';
            document.getElementById('tabLogin').classList.toggle('active', isLogin);
            document.getElementById('tabRegister').classList.toggle('active', !isLogin);
            document.getElementById('loginPanel').classList.toggle('active', isLogin);
            document.getElementById('registerPanel').classList.toggle('active', !isLogin);
            document.getElementById('authHeading').textContent = isLogin ? 'Welcome back' : 'Create your account';
            document.getElementById('authSub').textContent = isLogin ?
                'Sign in to access your dental records and appointments.' :
                'Register to schedule appointments and manage your care.';
            if (typeof clearErrors === 'function') clearErrors();
        }

        function togglePass(id, btn) {
            const inp = document.getElementById(id);
            const show = inp.type === 'text';
            inp.type = show ? 'password' : 'text';
            btn.querySelector('i').className = show ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
        }

        function openForgotPassword() {
            document.getElementById('forgotModal').classList.add('open');
            document.getElementById('forgotStepRequest').classList.add('active');
            document.getElementById('forgotStepSent').classList.remove('active');
            document.getElementById('forgotEmail').value = document.getElementById('loginEmail').value.trim();
            document.getElementById('forgotAlert').style.display = 'none';
            document.getElementById('errForgotEmail').style.display = 'none';
            setTimeout(() => document.getElementById('forgotEmail').focus(), 50);
        }

        function closeForgotPassword() {
            document.getElementById('forgotModal').classList.remove('open');
        }

        async function submitForgotPassword() {
            const email = document.getElementById('forgotEmail').value.trim();
            document.getElementById('errForgotEmail').style.display = 'none';
            document.getElementById('forgotAlert').style.display = 'none';

            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                showErr('errForgotEmail', 'Enter a valid email address.');
                return;
            }

            setLoading('forgotBtn', 'forgotSpinner', 'forgotBtnText', 'Sending…', true);

            try {
                const res = await fetch(API, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'forgot_password',
                        email
                    }),
                });

                let data = null;
                try {
                    data = await res.json();
                } catch (e) { /* handled below */ }

                if (!res.ok || !data) {
                    console.error('Forgot password: unexpected response', res.status);
                    showAlert('forgotAlert', 'We could not process your request right now. Please try again later.');
                    return;
                }
                if (!data.success) {
                    showAlert('forgotAlert', data.message || 'We could not process your request right now. Please try again later.');
                    return;
                }

                // Same message whether or not the address is registered —
                // avoids leaking which emails have accounts.
                const safeEmail = email.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
                closeForgotPassword();
                Swal.fire({
                    title: 'Check your email',
                    icon: 'info',
                    iconHtml: '<i class="fa-solid fa-envelope-open-text es-swal-glyph"></i>',
                    html: '<p class="es-swal-text">If an EssenciaSmile Patient Portal account is registered to ' +
                        '<strong>' + safeEmail + '</strong>, a password reset link is on its way.</p>' +
                        '<p class="es-swal-note"><i class="fa-solid fa-circle-info"></i><span>' +
                        'The link expires in <strong>30 minutes</strong> and works only once. ' +
                        'Don\'t see it after a few minutes? Check your <strong>Spam</strong> or <strong>Junk</strong> folder.</span></p>',
                    confirmButtonText: 'Got it',
                    buttonsStyling: false,
                    customClass: ES_SWAL_CLASS,
                });

            } catch (err) {
                console.error('Forgot password fetch error:', err);
                showAlert('forgotAlert', 'Could not reach the server. Make sure XAMPP/Apache is running.');
            } finally {
                setLoading('forgotBtn', 'forgotSpinner', 'forgotBtnText', '', false);
                document.getElementById('forgotBtnText').textContent = 'Send Reset Link';
            }
        }

        function resendForgotPassword() {
            submitForgotPassword();
        }

        document.getElementById('forgotModal')?.addEventListener('click', (e) => {
            if (e.target.id === 'forgotModal') closeForgotPassword();
        });

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && document.getElementById('forgotModal')?.classList.contains('open')) {
                closeForgotPassword();
            }
        });

        function toggleCustomSelect(id) {
            const wrap = document.getElementById(id);
            const isOpen = wrap.classList.contains('open');
            document.querySelectorAll('.modal-cs.open').forEach(el => el.classList.remove('open'));
            if (!isOpen) {
                wrap.classList.add('open');
                if (id === 'nationalitySelect') initNationalities();
                const search = wrap.querySelector('.modal-cs-search');
                if (search) {
                    search.value = '';
                    filterCustomSelect(search, id + 'Dropdown');
                    search.focus();
                }
            }
        }

        function filterCustomSelect(input, dropdownId) {
            const q = input.value.toLowerCase();
            document.querySelectorAll('#' + dropdownId + ' .modal-cs-list li').forEach(li => {
                li.style.display = li.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        }

        document.addEventListener('click', e => {
            if (!e.target.closest('.modal-cs'))
                document.querySelectorAll('.modal-cs.open').forEach(el => el.classList.remove('open'));
        });

        // Address list helpers (registration dropdowns)
        function addrAttr(s) {
            return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        function addrLabel(s) {
            return typeof normalizeAddressText === 'function' ? normalizeAddressText(s) : String(s);
        }

        function initProvinces() {
            if (!PH_DATA) {
                const label = document.getElementById('provinceValue');
                label.textContent = 'Loading addresses…';
                loadPhData().then(d => {
                    if (d) { label.textContent = 'Select province'; initProvinces(); }
                    else label.textContent = 'Address data unavailable';
                });
                return;
            }
            const ul = document.getElementById('provinceList');
            if (ul.children.length > 0) return;
            const provinces = {};
            Object.entries(PH_DATA).forEach(([regionCode, region]) => {
                Object.keys(region.province_list || {}).forEach(prov => {
                    provinces[prov] = regionCode;
                });
            });
            const sorted = Object.keys(provinces).sort();
            // Values are kept in data-* attributes (safe for names with apostrophes);
            // the list shows them nicely capitalised. See the click listeners below.
            ul.innerHTML = sorted.map(p =>
                `<li data-region="${addrAttr(provinces[p])}" data-province="${addrAttr(p)}">${addrAttr(addrLabel(p))}</li>`
            ).join('');
            console.log('[PH Address] Provinces loaded:', sorted.length);
        }
    </script>
</head>

<body class="auth-page">

    <!-- ── Top bar ───────────────────────────────────────────────────────── -->
    <?php include("partials/portalLogin-navbar.php") ?>

    <!-- ── Page body ─────────────────────────────────────────────────────── -->
    <div class="page-body">

        <!-- ── Left panel — dental background ───────────────────────────── -->
        <div class="left-panel">

            <!-- Overlay layers -->
            <div class="left-overlay"></div>

            <!-- Flowing waves -->
            <svg class="left-waves" viewBox="0 0 800 420" preserveAspectRatio="none" aria-hidden="true">
                <defs>
                    <linearGradient id="waveBack" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0" stop-color="#bfdcf7" stop-opacity=".55"/>
                        <stop offset="1" stop-color="#7fb8ec" stop-opacity=".7"/>
                    </linearGradient>
                    <linearGradient id="waveFront" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0" stop-color="#d8eafb" stop-opacity=".75"/>
                        <stop offset=".55" stop-color="#93c4f0" stop-opacity=".85"/>
                        <stop offset="1" stop-color="#5fa4e6" stop-opacity=".95"/>
                    </linearGradient>
                </defs>
                <path d="M0 250 C 180 300, 330 120, 520 70 C 640 40, 740 70, 800 110 L 800 420 L 0 420 Z" fill="url(#waveBack)"/>
                <path d="M0 330 C 200 360, 360 220, 540 160 C 660 125, 750 140, 800 170 L 800 420 L 0 420 Z" fill="url(#waveFront)"/>
            </svg>

            <div class="left-inner">
                <!-- Headline block -->
                <div class="left-content">
                    <h2>Your dental health,<br><span class="accent">always within reach.</span></h2>
                    <p>
                        Book appointments, track your treatment history, and manage your dental
                        care all in one place, whenever you need it.
                    </p>
                </div>

                <!-- Feature list -->
                <ul class="left-features">
                    <li class="feat-row">
                        <div class="feat-icon"><i class="fa-solid fa-calendar-check"></i></div>
                        <div class="feat-text">
                            <strong>Easy online appointment scheduling</strong>
                            <span>Book your visit in just a few clicks.</span>
                        </div>
                    </li>
                    <li class="feat-row">
                        <div class="feat-icon"><i class="fa-solid fa-file-medical"></i></div>
                        <div class="feat-text">
                            <strong>Access your dental records anytime</strong>
                            <span>Your treatment history is always available.</span>
                        </div>
                    </li>
                    <li class="feat-row">
                        <div class="feat-icon"><i class="fa-solid fa-wallet"></i></div>
                        <div class="feat-text">
                            <strong>View billing and payment history</strong>
                            <span>Keep track of your transactions with ease.</span>
                        </div>
                    </li>
                </ul>
            </div>

        </div><!-- /left-panel -->

        <!-- ── Right panel — auth form ───────────────────────────────────── -->
        <div class="right-panel">
            <div class="auth-card">

                <!-- Header -->
                <div class="auth-header">
                    <h1 id="authHeading">Welcome back</h1>
                    <p id="authSub">Sign in to access your dental records and appointments.</p>
                </div>

                <!-- Tab bar -->
                <div class="tab-bar" role="tablist">
                    <button class="tab-btn active" id="tabLogin"
                        onclick="switchTab('login')" role="tab"
                        aria-selected="true">Sign In</button>
                    <button class="tab-btn" id="tabRegister"
                        onclick="switchTab('register')" role="tab"
                        aria-selected="false">Create Account</button>
                </div>

                <!-- ── LOGIN PANEL ── -->
                <div id="loginPanel" class="form-panel active">

                    <div id="loginAlert" class="alert-bar"></div>

                    <div class="field">
                        <label for="loginEmail">Email Address</label>
                        <input type="email" id="loginEmail"
                            placeholder="you@email.com" autocomplete="email">
                        <div class="field-err" id="errLoginEmail"></div>
                    </div>

                    <div class="field">
                        <label for="loginPassword">Password</label>
                        <div class="pass-wrap">
                            <input type="password" id="loginPassword"
                                placeholder="Enter your password"
                                autocomplete="current-password">
                            <button class="pass-eye" type="button"
                                onclick="togglePass('loginPassword',this)"
                                tabindex="-1" aria-label="Toggle password">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                        <div class="field-err" id="errLoginPassword"></div>
                    </div>

                    <div class="forgot-pass-row">
                        <button type="button" class="forgot-pass-link" onclick="openForgotPassword()">Forgot password?</button>
                    </div>

                    <button class="btn-primary" id="loginBtn" onclick="doLogin()">
                        <span class="spinner" id="loginSpinner"></span>
                        <span class="btn-text" id="loginBtnText">Sign In</span>
                    </button>

                    <div class="auth-divider"><span>or</span></div>

                    <button class="btn-google" id="googleBtn" onclick="signInWithGoogle()">
                        <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
                            <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z" />
                            <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z" />
                            <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z" />
                            <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.18 1.48-4.97 2.35-8.16 2.35-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z" />
                        </svg>
                        Continue with Google
                    </button>

                </div><!-- /loginPanel -->

                <!-- ── REGISTER PANEL ── -->
                <div id="registerPanel" class="form-panel">

                    <div id="registerAlert" class="alert-bar"></div>

                    <!-- ── Step progress bar ── -->
                    <div class="reg-progress">
                        <div class="reg-prog-step active" id="prog1">
                            <div class="prog-dot"><span>1</span></div>
                            <div class="prog-label">Personal</div>
                        </div>
                        <div class="prog-line" id="progLine1"></div>
                        <div class="reg-prog-step" id="prog2">
                            <div class="prog-dot"><span>2</span></div>
                            <div class="prog-label">Contact</div>
                        </div>

                    </div>

                    <!-- ════════════════════════════════
                     STEP 1 — Basic Information
                     ════════════════════════════════ -->
                    <div id="regStep1" class="reg-step-panel active">

                        <div class="step-section-title">
                            <i class="fa-solid fa-user"></i> Basic Information
                        </div>

                        <div class="grid-2">
                            <div class="field">
                                <label for="reg_firstName">First Name</label>
                                <input type="text" id="reg_firstName" placeholder="Juan" autocomplete="given-name" onblur="normalizeNameInput(this)">
                                <div class="field-err" id="errRegFirst"></div>
                            </div>
                            <div class="field">
                                <label for="reg_lastName">Last Name</label>
                                <input type="text" id="reg_lastName" placeholder="Dela Cruz" autocomplete="family-name" onblur="normalizeNameInput(this)">
                                <div class="field-err" id="errRegLast"></div>
                            </div>
                        </div>

                        <div class="grid-mid">
                            <div class="field">
                                <label for="reg_middleName">Middle Name <span class="opt">(optional)</span></label>
                                <input type="text" id="reg_middleName" placeholder="Santos" autocomplete="additional-name" onblur="normalizeNameInput(this)">
                            </div>
                            <div class="field">
                                <label for="reg_suffix">Suffix <span class="opt">(Sr., Jr.)</span></label>
                                <select id="reg_suffix" class="js-custom-select">
                                    <option value="">None</option>
                                    <option>Jr.</option>
                                    <option>Sr.</option>
                                    <option>II</option>
                                    <option>III</option>
                                    <option>IV</option>
                                </select>
                            </div>
                        </div>

                        <div class="field">
                            <label for="reg_birthdate">Birthdate</label>
                            <input type="date" id="reg_birthdate" autocomplete="bday" class="js-date-picker" data-dp-max="today" placeholder="Select birthdate">
                            <div class="field-err" id="errRegBirth"></div>
                        </div>

                        <div class="grid-2">
                            <div class="field">
                                <label>Civil Status</label>
                                <div class="modal-cs" id="civilStatusSelect">
                                    <div class="modal-cs-trigger" onclick="toggleCustomSelect('civilStatusSelect')">
                                        <span class="modal-cs-value" id="civilStatusValue">Select status</span>
                                        <i class="fa-solid fa-chevron-down modal-cs-arrow"></i>
                                    </div>
                                    <div class="modal-cs-dropdown" id="civilStatusDropdown">
                                        <div class="modal-cs-search-wrap">
                                            <i class="fa-solid fa-magnifying-glass"></i>
                                            <input type="text" class="modal-cs-search" placeholder="Search..."
                                                oninput="filterCustomSelect(this,'civilStatusDropdown')">
                                        </div>
                                        <ul class="modal-cs-list" id="civilStatusList">
                                            <li onclick="selectCivilStatus('Single',this)">Single</li>
                                            <li onclick="selectCivilStatus('Married',this)">Married</li>
                                            <li onclick="selectCivilStatus('Widowed',this)">Widowed</li>
                                            <li onclick="selectCivilStatus('Separated',this)">Separated</li>
                                        </ul>
                                    </div>
                                </div>
                                <input type="hidden" id="reg_civil_status">
                            </div>
                            <div class="field">
                                <label>Nationality</label>
                                <div class="modal-cs" id="nationalitySelect">
                                    <div class="modal-cs-trigger" onclick="toggleCustomSelect('nationalitySelect')">
                                        <span class="modal-cs-value" id="nationalityValue">Select nationality</span>
                                        <i class="fa-solid fa-chevron-down modal-cs-arrow"></i>
                                    </div>
                                    <div class="modal-cs-dropdown" id="nationalityDropdown">
                                        <div class="modal-cs-search-wrap">
                                            <i class="fa-solid fa-magnifying-glass"></i>
                                            <input type="text" class="modal-cs-search" placeholder="Search nationality..."
                                                oninput="filterCustomSelect(this,'nationalityDropdown')">
                                        </div>
                                        <ul class="modal-cs-list" id="nationalityList"></ul>
                                    </div>
                                </div>
                                <input type="hidden" id="reg_nationality">
                            </div>
                        </div>

                        <div class="field">
                            <label for="reg_gender">Gender</label>
                            <select id="reg_gender" name="reg_gender" class="js-custom-select" data-cs-search aria-required="true" aria-describedby="errRegGender">
                                <option value="">Select gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Non-binary">Non-binary</option>
                                <option value="Another gender">Another gender</option>
                                <option value="Prefer not to say">Prefer not to say</option>
                            </select>
                            <div class="field-err" id="errRegGender" role="alert"></div>
                        </div>

                        <div class="step-nav">
                            <span></span>
                            <button class="btn-primary step-btn-next" type="button" onclick="regNextStep(1)">
                                Next <i class="fa-solid fa-arrow-right"></i>
                            </button>
                        </div>

                        <p class="foot-note">
                            Already have an account?
                            <button class="foot-link" onclick="switchTab('login')">Sign in</button>
                        </p>

                    </div><!-- /regStep1 -->

                    <!-- ════════════════════════════════
                     STEP 2 — Contact Information
                     ════════════════════════════════ -->
                    <div id="regStep2" class="reg-step-panel">

                        <div class="step-section-title">
                            <i class="fa-solid fa-address-book"></i> Contact Information
                        </div>

                        <div class="grid-2">
                            <div class="field">
                                <label for="reg_contact">Contact Number</label>
                                <div class="phone-wrap">
                                    <span class="phone-prefix">+63</span>
                                    <input type="tel" id="reg_contact" placeholder="9XX XXX XXXX"
                                        maxlength="10" autocomplete="tel">
                                </div>
                                <div class="field-err" id="errRegContact"></div>
                            </div>
                            <div class="field">
                                <label for="reg_homePhone">Home Phone <span class="opt">(optional)</span></label>
                                <input type="tel" id="reg_homePhone" placeholder="(02) XXXX-XXXX">
                            </div>
                        </div>

                        <div class="field">
                            <label for="reg_email">Email Address
                                <span class="info-tip">
                                    <button type="button" class="info-tip-btn" aria-label="Verification info"
                                        aria-describedby="regEmailTip" aria-expanded="false">
                                        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                                    </button>
                                    <span class="info-tip-bubble" id="regEmailTip" role="tooltip">
                                        Before booking an appointment, you'll need to verify <strong>either</strong> your email address <strong>or</strong> your mobile number. Only one is required.
                                    </span>
                                </span>
                            </label>
                            <input type="email" id="reg_email" placeholder="you@email.com" autocomplete="email">
                            <div class="field-err" id="errRegEmail"></div>
                        </div>

                        <div class="field">
                            <label for="reg_address">Home Address</label>
                            <input type="text" id="reg_address" placeholder="House No., Street, Barangay"
                                autocomplete="street-address" onblur="normalizeAddressInput(this)">
                            <div class="field-err" id="errRegAddress"></div>
                        </div>

                        <div class="grid-2">
                            <div class="field">
                                <label>Province</label>
                                <div class="modal-cs" id="provinceSelect">
                                    <div class="modal-cs-trigger" onclick="toggleCustomSelect('provinceSelect')">
                                        <span class="modal-cs-value" id="provinceValue">Select province</span>
                                        <i class="fa-solid fa-chevron-down modal-cs-arrow"></i>
                                    </div>
                                    <div class="modal-cs-dropdown" id="provinceDropdown">
                                        <div class="modal-cs-search-wrap">
                                            <i class="fa-solid fa-magnifying-glass"></i>
                                            <input type="text" class="modal-cs-search" placeholder="Search province..." oninput="filterCustomSelect(this,'provinceDropdown')">
                                        </div>
                                        <ul class="modal-cs-list" id="provinceList"></ul>
                                    </div>
                                </div>
                                <input type="hidden" id="reg_province">
                                <div class="field-err" id="errRegProvince"></div>
                            </div>
                            <div class="field">
                                <label>City / Municipality</label>
                                <div class="modal-cs" id="citySelect">
                                    <div class="modal-cs-trigger" onclick="toggleCustomSelect('citySelect')">
                                        <span class="modal-cs-value" id="cityValue">Select province first</span>
                                        <i class="fa-solid fa-chevron-down modal-cs-arrow"></i>
                                    </div>
                                    <div class="modal-cs-dropdown" id="cityDropdown">
                                        <div class="modal-cs-search-wrap">
                                            <i class="fa-solid fa-magnifying-glass"></i>
                                            <input type="text" class="modal-cs-search" placeholder="Search city..." oninput="filterCustomSelect(this,'cityDropdown')">
                                        </div>
                                        <ul class="modal-cs-list" id="cityList"></ul>
                                    </div>
                                </div>
                                <input type="hidden" id="reg_city">
                                <div class="field-err" id="errRegCity"></div>
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="field">
                                <label>Barangay</label>
                                <div class="modal-cs" id="barangaySelect">
                                    <div class="modal-cs-trigger" onclick="toggleCustomSelect('barangaySelect')">
                                        <span class="modal-cs-value" id="barangayValue">Select city first</span>
                                        <i class="fa-solid fa-chevron-down modal-cs-arrow"></i>
                                    </div>
                                    <div class="modal-cs-dropdown" id="barangayDropdown">
                                        <div class="modal-cs-search-wrap">
                                            <i class="fa-solid fa-magnifying-glass"></i>
                                            <input type="text" class="modal-cs-search" placeholder="Search barangay..." oninput="filterCustomSelect(this,'barangayDropdown')">
                                        </div>
                                        <ul class="modal-cs-list" id="barangayList"></ul>
                                    </div>
                                </div>
                                <input type="hidden" id="reg_barangay">
                            </div>
                            <div class="field">
                                <label for="reg_zip">ZIP Code</label>
                                <input type="text" id="reg_zip" placeholder="e.g. 1000"
                                    maxlength="4" inputmode="numeric">

                            </div>
                        </div>

                        <div class="step-section-title" style="margin-top:1.2rem;">
                            <i class="fa-solid fa-lock"></i> Account Credentials
                        </div>

                        <div class="field">
                            <label for="reg_password">Password</label>
                            <div class="pass-wrap">
                                <input type="password" id="reg_password" placeholder="Min. 8 characters"
                                    autocomplete="new-password">
                                <button class="pass-eye" type="button" onclick="togglePass('reg_password',this)"
                                    tabindex="-1" aria-label="Toggle password">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                            <div class="field-err" id="errRegPass"></div>
                        </div>

                        <div class="field">
                            <label for="reg_confirm">Confirm Password</label>
                            <div class="pass-wrap">
                                <input type="password" id="reg_confirm" placeholder="Re-enter password"
                                    autocomplete="new-password">
                                <button class="pass-eye" type="button" onclick="togglePass('reg_confirm',this)"
                                    tabindex="-1" aria-label="Toggle password">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                            <div class="field-err" id="errRegConfirm"></div>
                        </div>

                        <!-- ── Terms and Conditions ── -->
                        <div class="field terms-field">
                            <label class="terms-check">
                                <input type="checkbox" id="reg_terms">
                                <span>
                                    I have read and agree to the
                                    <a href="portal-terms.php" target="_blank" rel="noopener" class="terms-link">Terms and Conditions</a>
                                </span>
                            </label>
                            <div class="field-err" id="errRegTerms"></div>
                        </div>

                        <div class="step-nav">
                            <button class="btn-step-back" type="button" onclick="regPrevStep(2)">
                                <i class="fa-solid fa-arrow-left"></i> Back
                            </button>
                            <button class="btn-primary step-btn-next" id="regBtn" onclick="submitRegistration()">
                                <span class="spinner" id="regSpinner"></span>
                                <span class="btn-text" id="regBtnText">Create Account</span>
                            </button>
                        </div>

                    </div><!-- /regStep2 -->



                </div><!-- /registerPanel -->

            </div><!-- /auth-card -->
        </div><!-- /right-panel -->

    </div><!-- /page-body -->

     <!-- ── Forgot Password Modal ─────────────────────────────────────── -->
    <div class="modal-overlay" id="forgotModal">
        <div class="modal-card">
            <button class="modal-close" onclick="closeForgotPassword()" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>

            <!-- Step: Request reset link -->
            <div id="forgotStepRequest" class="forgot-step active">
                <div class="modal-icon"><i class="fa-solid fa-key"></i></div>
                <h2>Forgot your password?</h2>
                <p>Enter the email linked to your account and we'll send you a link to reset your password.</p>

                <div id="forgotAlert" class="alert-bar"></div>

                <div class="field">
                    <label for="forgotEmail">Email Address</label>
                    <input type="email" id="forgotEmail" placeholder="you@email.com" autocomplete="email">
                    <div class="field-err" id="errForgotEmail"></div>
                </div>

                <button class="btn-primary" id="forgotBtn" onclick="submitForgotPassword()">
                    <span class="spinner" id="forgotSpinner"></span>
                    <span class="btn-text" id="forgotBtnText">Send Reset Link</span>
                </button>
            </div>

            <!-- Step: Confirmation -->
            <div id="forgotStepSent" class="forgot-step">
                <div class="modal-icon modal-icon-success"><i class="fa-solid fa-envelope-circle-check"></i></div>
                <h2>Check your email</h2>
                <p>We've sent a password reset link to <strong id="forgotSentEmail"></strong>. The link expires in 30 minutes.</p>

                <button class="btn-primary" onclick="closeForgotPassword()">Got it</button>

                <p class="foot-note">
                    Didn't get it? <button class="foot-link" onclick="resendForgotPassword()">Resend email</button>
                </p>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
    <script src="assets/vendors/js/sweetalert2-11.26.25.all.min.js"></script>
    <script src="assets/js/es-swal.js?v=2"></script>
    <script src="assets/js/nationalities.js"></script>
    <script src="assets/js/custom-select.js?v=2"></script>
    <script>
        // ── API path ─────────────────────────────────────────────────────────
        const API = '<?= $API_PATH ?>';

        // ── Supabase client ──────────────────────────────────────────────────
        const SUPABASE_URL = <?= json_encode($SUPABASE_PUBLIC_URL, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>;
        const SUPABASE_ANON = <?= json_encode($SUPABASE_PUBLISHABLE_KEY, JSON_HEX_TAG) ?>; // sb_publishable_ key

        // Supabase is initialized lazily — only when first needed
        let _supabase = null;

        function getSupabase() {
            if (!_supabase) {
                if (!window.supabase) {
                    throw new Error('Supabase SDK failed to load. Check your internet connection.');
                }
                _supabase = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON);
            }
            return _supabase;
        }




        // ── Error helpers ────────────────────────────────────────────────────
        function clearErrors() {
            document.querySelectorAll('.field-err').forEach(e => {
                e.style.display = 'none';
                e.textContent = '';
            });
            document.querySelectorAll('.alert-bar').forEach(e => {
                e.style.display = 'none';
                e.className = 'alert-bar';
            });
        }

        function showErr(id, msg) {
            const el = document.getElementById(id);
            if (el) {
                el.textContent = msg;
                el.style.display = 'block';
            }
        }

        function showAlert(id, msg, type = 'danger') {
            const el = document.getElementById(id);
            if (!el) return;
            el.className = 'alert-bar ' + type;
            el.textContent = msg;
            el.style.display = 'block';
        }

        // ── Spinner helpers ──────────────────────────────────────────────────
        function setLoading(btnId, spinnerId, textId, label, loading) {
            const btn = document.getElementById(btnId);
            const sp = document.getElementById(spinnerId);
            const tx = document.getElementById(textId);
            if (!btn) return;
            btn.disabled = loading;
            if (sp) sp.style.display = loading ? 'block' : 'none';
            if (tx) tx.textContent = loading ? label : tx.dataset.orig || tx.textContent;
            if (loading && tx) tx.dataset.orig = tx.textContent;
        }
        // ── Name capitalisation (same rules as controllers/name_helper.php) ──
        // Re-cases a word only when it is ALL CAPS, all lower-case, or starts
        // lower-case, or has two capitals in a row (KUPal); intentional mixed
        // case (DeVera, McArthur) is kept.
        // This is a live preview only — the server applies the same rules on save.
        function normalizeNamePart(part) {
            if (!part) return '';
            const upper = part.toUpperCase(), lower = part.toLowerCase();
            if (upper === lower) return part;
            const first = part.charAt(0);
            const startsLower = first === first.toLowerCase() && first !== first.toUpperCase();
            // Two capitals in a row inside a mixed-case word is a Shift/Caps slip (KUPal, JUan)
            // Two capitals in a row inside a mixed-case word is a Shift/Caps slip (KUPal, JUan)
            if (part !== upper && part !== lower && !startsLower && !/\p{Lu}{2}/u.test(part)) return part;
            let out = lower.charAt(0).toUpperCase() + lower.slice(1);
            out = out.replace(/^(\p{L})(['’])(\p{L})/u, (m, a, b, c) => a + b + c.toUpperCase());
            out = out.replace(/^(Mc)(\p{L})/u, (m, a, b) => a + b.toUpperCase());
            return out;
        }

        function normalizePersonName(name) {
            const clean = String(name || '').replace(/\s+/g, ' ').trim();
            if (!clean) return '';
            return clean.split(' ')
                .map(w => w.split('-').map(normalizeNamePart).join('-'))
                .join(' ');
        }

        function normalizeNameInput(el) {
            const fixed = normalizePersonName(el.value);
            if (fixed !== el.value) el.value = fixed;
        }

        // ── Address capitalisation (same rules as normalize_address_text()) ──
        // Only letters change; numbers, ZIP codes and punctuation stay as typed.
        const ADDRESS_KEEP_UPPER = ['NCR', 'BGC', 'CAR', 'ARMM', 'BARMM', 'CALABARZON', 'MIMAROPA', 'SOCCSKSARGEN',
            'EDSA', 'LRT', 'MRT', 'SM', 'UP', 'PUP', 'UST', 'FEU', 'DLSU', 'PO', 'PSC'
        ];
        const ADDRESS_LOWER_WORDS = ['of', 'and'];

        function normalizeAddressText(text) {
            const clean = String(text || '').replace(/\s+/g, ' ').trim();
            if (!clean) return '';
            let seenWord = false;
            return clean.replace(/[\p{L}\p{M}\p{N}'’]+/gu, function(w) {
                if (/\p{N}/u.test(w)) {
                    const o = w.match(/^(\d+)(st|nd|rd|th)$/i);
                    return o ? o[1] + o[2].toLowerCase() : w;
                }
                const first = !seenWord;
                seenWord = true;
                const upper = w.toUpperCase(), lower = w.toLowerCase();
                const uniform = (w === upper || w === lower);
                if (uniform && ADDRESS_KEEP_UPPER.includes(upper)) return upper;
                if (uniform && /^(I{1,3}|IV|VI{0,3}|IX|X{1,3})$/.test(upper)) return upper;
                if (uniform && !first && ADDRESS_LOWER_WORDS.includes(lower)) return lower;
                return normalizeNamePart(w);
            });
        }

        function normalizeAddressInput(el) {
            const fixed = normalizeAddressText(el.value);
            if (fixed !== el.value) el.value = fixed;
        }

        // ── LOGIN ────────────────────────────────────────────────────────────
        async function doLogin() {
            clearErrors();
            const email = document.getElementById('loginEmail').value.trim();
            const password = document.getElementById('loginPassword').value;

            let ok = true;
            if (!email) {
                showErr('errLoginEmail', 'Email is required.');
                ok = false;
            }
            if (!password) {
                showErr('errLoginPassword', 'Password is required.');
                ok = false;
            }
            if (!ok) return;

            setLoading('loginBtn', 'loginSpinner', 'loginBtnText', 'Signing in…', true);

            try {
                const res = await fetch(API, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'login',
                        email,
                        password
                    }),
                });

                if (!res.ok) {
                    const raw = await res.text();
                    console.error('Login HTTP error', res.status, raw);
                    showAlert('loginAlert', `Server error (${res.status}). Check the console for details.`);
                    return;
                }

                let data;
                try {
                    data = await res.json();
                } catch (parseErr) {
                    const raw = await res.text().catch(() => '(unreadable)');
                    console.error('JSON parse error — raw response:', raw);
                    showAlert('loginAlert', 'The server returned an unexpected response. Check the console.');
                    return;
                }

                if (data.success) {
                    window.location.href = 'portal-dashboard.php';
                } else if (data.code === 'EMAIL_NOT_VERIFIED') {
                    showAlert('loginAlert', 'Please verify your email address or your phone number to continue.');
                } else {
                    showAlert('loginAlert', data.message || 'Invalid credentials.');
                }
            } catch (err) {
                console.error('Login fetch error:', err);
                showAlert('loginAlert', 'Could not reach the server. Make sure XAMPP/Apache is running and the API file exists at: ' + API);
            } finally {
                setLoading('loginBtn', 'loginSpinner', 'loginBtnText', '', false);
                document.getElementById('loginBtnText').textContent = 'Sign In';
            }
        }



        // ── INFO TOOLTIPS (hover on desktop, tap on touch) ────────────────────
        (function() {
            const closeAll = (except) => {
                document.querySelectorAll('.info-tip.is-open').forEach(t => {
                    if (t === except) return;
                    t.classList.remove('is-open');
                    t.querySelector('.info-tip-btn').setAttribute('aria-expanded', 'false');
                });
            };
            document.addEventListener('click', (e) => {
                const btn = e.target.closest('.info-tip-btn');
                if (!btn) return closeAll();
                e.preventDefault();
                const tip = btn.closest('.info-tip');
                closeAll(tip);
                const open = tip.classList.toggle('is-open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') closeAll();
            });
        })();

        // ── AGE AUTO-CALCULATE ────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', () => {});

        // ── PH ADDRESS — JSON-DRIVEN (cluster.json) ──────────────────────────
        // Place cluster.json at: assets/json/cluster.json
        // Download: https://github.com/arkeensalvador/ph-address-dropdown/blob/main/cluster.json

        // Loaded as a static file (1.4 MB, cached by the browser) instead of being
        // inlined into the login page. Fetched after the page has loaded; the
        // registration step that needs it waits for it (see initProvinces).
        let PH_DATA = null;
        let _phDataPromise = null;
        function loadPhData() {
            if (!_phDataPromise) {
                _phDataPromise = fetch('assets/json/cluster.json')
                    .then(r => r.ok ? r.json() : null)
                    .then(d => (PH_DATA = d))
                    .catch(() => (_phDataPromise = null, null));   // allow a retry later
            }
            return _phDataPromise;
        }
        window.addEventListener('load', () => setTimeout(loadPhData, 0));

        // ZIP lookup: barangay name (lowercase) → ZIP code
        const ZIP_MAP = {
            // ── City-level fallbacks (matches cluster.json uppercase → lowercased) ──
            'city of caloocan': '1400',
            'caloocan city': '1400',
            'city of las piñas': '1740',
            'city of las pinas': '1740',
            'city of makati': '1200',
            'makati city': '1200',
            'city of malabon': '1470',
            'malabon city': '1470',
            'city of mandaluyong': '1550',
            'mandaluyong city': '1550',
            'city of manila': '1000',
            'manila': '1000',
            'city of marikina': '1800',
            'marikina city': '1800',
            'city of muntinlupa': '1770',
            'muntinlupa city': '1770',
            'city of navotas': '1485',
            'navotas city': '1485',
            'city of parañaque': '1700',
            'city of paranaque': '1700',
            'parañaque city': '1700',
            'paranaque city': '1700',
            'city of pasay': '1300',
            'pasay city': '1300',
            'pasig city': '1600',
            'city of pasig': '1600',
            'pateros': '1620',
            'quezon city': '1100',
            'city of san juan': '1500',
            'san juan city': '1500',
            'city of taguig': '1630',
            'taguig city': '1630',
            'city of valenzuela': '1440',
            'valenzuela city': '1440',
            // Bulacan
            'city of malolos': '3000',
            'malolos city': '3000',
            'city of meycauayan': '3020',
            'meycauayan city': '3020',
            'municipality of marilao': '3019',
            'marilao': '3019',
            // Cavite
            'city of bacoor': '4102',
            'bacoor city': '4102',
            'city of dasmariñas': '4114',
            'city of dasmarinas': '4114',
            'dasmariñas city': '4114',
            'dasmarinas city': '4114',
            'city of imus': '4103',
            'imus city': '4103',
            'tagaytay city': '4120',
            // Laguna
            'city of calamba': '4027',
            'calamba city': '4027',
            'city of santa rosa': '4026',
            'santa rosa city': '4026',
            'city of biñan': '4024',
            'city of binan': '4024',
            'biñan city': '4024',
            'city of san pablo': '4000',
            'san pablo city': '4000',
            'city of cabuyao': '4025',
            'cabuyao city': '4025',
            // Rizal
            'city of antipolo': '1870',
            'antipolo city': '1870',
            'municipality of cainta': '1900',
            'cainta': '1900',
            'municipality of taytay': '1920',
            'taytay': '1920',
            // Cebu
            'city of cebu': '6000',
            'cebu city': '6000',
            'city of lapu-lapu': '6015',
            'lapu-lapu city': '6015',
            'city of mandaue': '6014',
            'mandaue city': '6014',
            'city of talisay': '6045',
            'talisay city': '6045',
            // Davao
            'city of davao': '8000',
            'davao city': '8000',
            'city of digos': '8002',
            'digos city': '8002',
            // Pampanga
            'city of angeles': '2009',
            'angeles city': '2009',
            'city of san fernando': '2000',
            'san fernando city': '2000',
            'city of mabalacat': '2010',
            'mabalacat city': '2010',
            // Batangas
            'city of batangas': '4200',
            'batangas city': '4200',
            'city of lipa': '4217',
            'lipa city': '4217',
            'city of tanauan': '4232',
            'tanauan city': '4232',
            // Iloilo
            'city of iloilo': '5000',
            'iloilo city': '5000',
            // ── Barangay-level overrides ──────────────────────────────────────────
            'bel-air': '1209',
            'rockwell': '1210',
            'legazpi village': '1229',
            'san antonio': '1203',
            'urdaneta': '1224',
            'san lorenzo': '1223',
            'magallanes': '1232',
            'guadalupe nuevo': '1212',
            'guadalupe viejo': '1211',
            'bangkal': '1233',
            'palanan': '1235',
            'cembo': '1213',
            'south cembo': '1213',
            'west rembo': '1216',
            'east rembo': '1215',
            'comembo': '1214',
            'pitogo': '1214',
            'tejeros': '1205',
            'singkamas': '1204',
            'la paz': '1204',
            'olympia': '1207',
            'pio del pilar': '1230',
            'carmona': '1208',
            'ermita': '1000',
            'quiapo': '1001',
            'intramuros': '1002',
            'malate': '1004',
            'paco': '1007',
            'sampaloc': '1008',
            'santa ana': '1009',
            'pandacan': '1011',
            'tondo': '1013',
            'santa mesa': '1016',
            'port area': '1018',
            'binondo': '1006',
            'san miguel': '1005',
            'diliman': '1101',
            'east kamias': '1102',
            'west kamias': '1102',
            'kamuning': '1103',
            'south triangle': '1103',
            'pinyahan': '1100',
            'del monte': '1105',
            'katipunan': '1105',
            'bahay toro': '1106',
            'new era': '1107',
            'loyola heights': '1108',
            'cubao': '1109',
            'ugong norte': '1110',
            'libis': '1110',
            'white plains': '1110',
            'batasan hills': '1126',
            'commonwealth': '1121',
            'holy spirit': '1127',
            'culiat': '1128',
            'fairview': '1118',
            'payatas': '1119',
            'kapitolyo': '1603',
            'ortigas center': '1605',
            'maybunga': '1607',
            'manggahan': '1611',
            'baclaran': '1720',
            'bf homes': '1710',
            'moonwalk': '1706',
            'merville': '1709',
            'alabang': '1771',
            'ayala alabang': '1780',
            'bayanan': '1773',
            'zapote': '1740',
            'grace park': '1403',
            'camarin': '1422',
            'longos': '1484',
            'tinajeros': '1477',
            'tonsuya': '1472',
            'acacia': '1474',
            'fort bonifacio (bgc)': '1634',
            'central bicutan': '1631',
            'upper bicutan': '1631',
            'lower bicutan': '1631',
            'western bicutan': '1631',
            'central signal village': '1632',
            'canlubang': '4028',
            'wack-wack greenhills': '1555',
            'addition hills': '1550',
            'greenfields': '1502',
        } // ── ADDRESS DROPDOWN FUNCTIONS ───────────────────────────────────────
        function resetCS(id, valueId, hiddenId, placeholder) {
            document.getElementById(hiddenId).value = '';
            const vEl = document.getElementById(valueId);
            vEl.textContent = placeholder;
            vEl.classList.remove('cs-selected');
            const ul = document.getElementById(id + 'List');
            if (ul) ul.innerHTML = '';
            document.getElementById(id)?.classList.remove('open');
        }

        function selectProvince(regionCode, name, li) {
            document.getElementById('reg_province').value = name;
            const vEl = document.getElementById('provinceValue');
            vEl.textContent = addrLabel(name);
            vEl.classList.add('cs-selected');
            document.getElementById('provinceSelect').classList.remove('open');
            li.closest('ul').querySelectorAll('li').forEach(l => l.classList.remove('active'));
            li.classList.add('active');
            resetCS('citySelect', 'cityValue', 'reg_city', 'Select city');
            resetCS('barangaySelect', 'barangayValue', 'reg_barangay', 'Select city first');
            document.getElementById('reg_zip').value = '';
            const cities = Object.keys(PH_DATA[regionCode]?.province_list[name]?.municipality_list || {}).sort();
            document.getElementById('cityList').innerHTML = cities.map(c =>
                `<li data-region="${addrAttr(regionCode)}" data-province="${addrAttr(name)}" data-city="${addrAttr(c)}">${addrAttr(addrLabel(c))}</li>`
            ).join('');
        }

        function selectCity(regionCode, provinceName, cityName, li) {
            document.getElementById('reg_city').value = cityName;
            const vEl = document.getElementById('cityValue');
            vEl.textContent = addrLabel(cityName);
            vEl.classList.add('cs-selected');
            document.getElementById('citySelect').classList.remove('open');
            li.closest('ul').querySelectorAll('li').forEach(l => l.classList.remove('active'));
            li.classList.add('active');
            resetCS('barangaySelect', 'barangayValue', 'reg_barangay', 'Select barangay');
            document.getElementById('reg_zip').value = '';
            const brgys = PH_DATA[regionCode]?.province_list[provinceName]?.municipality_list[cityName]?.barangay_list || [];
            document.getElementById('barangayList').innerHTML = brgys.length ?
                brgys.map(b => `<li data-barangay="${addrAttr(b)}">${addrAttr(addrLabel(b))}</li>`).join('') :
                '<li class="cs-empty" style="color:var(--muted);cursor:default;font-style:italic;padding:.5rem .75rem;">No barangay data</li>';
        }

        function selectBarangay(name, li) {
            document.getElementById('reg_barangay').value = name;
            const vEl = document.getElementById('barangayValue');
            vEl.textContent = addrLabel(name);
            vEl.classList.add('cs-selected');
            document.getElementById('barangaySelect').classList.remove('open');
            li.closest('ul').querySelectorAll('li').forEach(l => l.classList.remove('active'));
            li.classList.add('active');
            const cityName = document.getElementById('reg_city').value;
            const zip = ZIP_MAP[name.toLowerCase().trim()] ||
                ZIP_MAP[cityName.toLowerCase().trim()] ||
                '';
            const zipEl = document.getElementById('reg_zip');
            if (zip) {
                zipEl.value = zip;
                zipEl.style.borderColor = '#16a34a';
                zipEl.style.background = '#f0fdf4';
                setTimeout(() => {
                    zipEl.style.borderColor = '';
                    zipEl.style.background = '';
                }, 2000);
            } else {
                zipEl.value = '';
            }
        }

        // One click listener per address list (instead of inline onclick per item)
        [
            ['provinceList', li => selectProvince(li.dataset.region, li.dataset.province, li)],
            ['cityList', li => selectCity(li.dataset.region, li.dataset.province, li.dataset.city, li)],
            ['barangayList', li => li.dataset.barangay !== undefined && selectBarangay(li.dataset.barangay, li)],
        ].forEach(([id, handler]) => {
            const ul = document.getElementById(id);
            if (ul) ul.addEventListener('click', e => {
                const li = e.target.closest('li');
                if (li && ul.contains(li)) handler(li);
            });
        });

        function selectCivilStatus(value, li) {
            document.getElementById('reg_civil_status').value = value;
            const vEl = document.getElementById('civilStatusValue');
            vEl.textContent = value;
            vEl.classList.add('cs-selected');
            document.getElementById('civilStatusSelect').classList.remove('open');
            li.closest('ul').querySelectorAll('li').forEach(l => l.classList.remove('active'));
            li.classList.add('active');
        }
        // Shared list: assets/js/nationalities.js
        const NATIONALITIES = window.ES_NATIONALITIES || ['Filipino'];

        function initNationalities() {
            const ul = document.getElementById('nationalityList');
            if (!ul || ul.children.length > 0) return;
            ul.innerHTML = NATIONALITIES.map(n =>
                `<li onclick="selectNationality('${n.replace(/'/g,"\\'")}',this)">${n}</li>`
            ).join('');
        }

        function selectNationality(value, li) {
            document.getElementById('reg_nationality').value = value;
            const vEl = document.getElementById('nationalityValue');
            vEl.textContent = value;
            vEl.classList.add('cs-selected');
            document.getElementById('nationalitySelect').classList.remove('open');
            li.closest('ul').querySelectorAll('li').forEach(l => l.classList.remove('active'));
            li.classList.add('active');
        }

        // ── REGISTER ─────────────────────────────────────────────────────────
        async function submitRegistration() {
            clearErrors();

            // Contact step (incl. Password = Confirm Password) must pass before submitting.
            if (!regValidateStep(2)) {
                const firstErr = [...document.querySelectorAll('#regStep2 .field-err')]
                    .find(e => e.style.display === 'block');
                firstErr?.closest('.field')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            // Terms and Conditions must be accepted before we submit.
            const agreedTerms = document.getElementById('reg_terms').checked;
            if (!agreedTerms) {
                showErr('errRegTerms', 'You must agree to the Terms and Conditions to create an account.');
                return;
            }

            setLoading('regBtn', 'regSpinner', 'regBtnText', 'Creating account…', true);
            try {
                const res = await fetch(API, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'register',
                        first_name: document.getElementById('reg_firstName').value.trim(),
                        last_name: document.getElementById('reg_lastName').value.trim(),
                        middle_name: document.getElementById('reg_middleName').value.trim(),
                        suffix: document.getElementById('reg_suffix').value,
                        birthdate: document.getElementById('reg_birthdate').value,
                        civil_status: document.getElementById('reg_civil_status').value,
                        nationality: document.getElementById('reg_nationality').value.trim(),
                        gender: document.getElementById('reg_gender').value,
                        contact_number: '+63' + document.getElementById('reg_contact').value.trim(),
                        home_phone: document.getElementById('reg_homePhone').value.trim(),
                        email: document.getElementById('reg_email').value.trim(),
                        password: document.getElementById('reg_password').value,
                        confirm_password: document.getElementById('reg_confirm').value,
                        address: document.getElementById('reg_address').value.trim(),
                        province: document.getElementById('reg_province').value,
                        city: document.getElementById('reg_city').value,
                        barangay: document.getElementById('reg_barangay').value,
                        zip: document.getElementById('reg_zip').value.trim(),
                        agreed_terms: agreedTerms,
                    }),
                });
                if (!res.ok) {
                    showAlert('registerAlert', `Server error (${res.status}).`);
                    return;
                }
                let data;
                try {
                    data = await res.json();
                } catch (e) {
                    showAlert('registerAlert', 'Unexpected server response.');
                    return;
                }
                if (data.success) {
                    Swal.fire({
                        title: 'Account Created!',
                        icon: 'success',
                        iconHtml: '<i class="fa-solid fa-circle-check es-swal-glyph"></i>',
                        html: '<p class="es-swal-text">Your EssenciaSmile Patient Portal account has been created. ' +
                            'Sign in with your email and password to continue.</p>' +
                            '<p class="es-swal-note"><i class="fa-solid fa-shield-halved"></i><span>' +
                            '<strong>Before booking:</strong> verify either your email address ' +
                            'or your phone number from your portal. You only need to verify one of them.</span></p>',
                        confirmButtonText: '<i class="fa-solid fa-right-to-bracket"></i> Sign In Now',
                        buttonsStyling: false,
                        customClass: ES_SWAL_CLASS,
                    }).then(() => {
                        regGoToStep(1);
                        switchTab('login');
                    });
                } else {
                    if (data.code === 'PASSWORD_MISMATCH') {
                        showErr('errRegConfirm', data.message);
                        document.getElementById('reg_confirm').closest('.field')
                            ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    } else if (data.message?.includes('email already exists')) {
                        showErr('errRegEmail', 'You already have an account with this email.');
                    } else if (data.code === 'TERMS_NOT_AGREED') {
                        showErr('errRegTerms', data.message || 'You must agree to the Terms and Conditions to create an account.');
                    } else {
                        showAlert('registerAlert', data.message || 'Registration failed. Please try again.');
                    }
                }
            } catch (err) {
                showAlert('registerAlert', 'Could not reach the server. Make sure XAMPP is running.');
            } finally {
                setLoading('regBtn', 'regSpinner', 'regBtnText', '', false);
                document.getElementById('regBtnText').textContent = 'Create Account';
            }
        }

        async function signInWithGoogle() {
            const btn = document.getElementById('googleBtn');
            btn.disabled = true;
            btn.classList.add('loading');

            try {
                const {
                    data,
                    error
                } = await getSupabase().auth.signInWithOAuth({
                    provider: 'google',
                    options: {
                        redirectTo: window.location.href.replace(/\/[^\/]*$/, '/portal-google-callback.php'),
                        skipBrowserRedirect: true, // ← don't redirect the main tab
                    }
                });

                if (error) throw error;

                // Open the Google consent screen in a popup
                const popup = window.open(
                    data.url,
                    'GoogleSignIn',
                    'width=500,height=600,top=' + (screen.height / 2 - 300) +
                    ',left=' + (screen.width / 2 - 250) +
                    ',toolbar=no,menubar=no,scrollbars=yes,resizable=no'
                );

                if (!popup) {
                    // Popup was blocked — fall back to normal redirect
                    window.location.href = data.url;
                    return;
                }
                // ── Listen for success message from popup ──
                window.addEventListener('message', async function onMessage(e) {
                    if (e.data === 'google_auth_success') {
                        window.removeEventListener('message', onMessage);
                        clearInterval(timer);
                        window.location.href = 'portal-dashboard.php';
                    }
                });

                // Poll until the popup closes
                const timer = setInterval(() => {
                    if (popup.closed) {
                        clearInterval(timer);
                        btn.disabled = false;
                        btn.classList.remove('loading');

                        // Check if login succeeded by pinging the session
                        checkSessionAfterPopup();
                    }
                }, 500);

            } catch (err) {
                btn.disabled = false;
                btn.classList.remove('loading');
                showAlert('loginAlert', 'Google sign-in failed: ' + err.message);
            }
        }

        async function checkSessionAfterPopup() {
            try {
                const res = await fetch('api/portal_auth.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'check_session'
                    }),
                });
                const data = await res.json();
                if (data.logged_in) {
                    window.location.href = 'portal-dashboard.php';
                }
                // If not logged in, user just closed the popup — do nothing
            } catch (err) {
                console.error('Session check failed:', err);
            }
        }

        // ── Enter key ─────────────────────────────────────────────────────────
        document.addEventListener('keydown', e => {
            if (e.key !== 'Enter') return;
            if (document.getElementById('loginPanel').classList.contains('active')) doLogin();
        });
    </script>
</body>

</html>