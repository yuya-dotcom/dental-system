<?php
//portal-login.php
session_start();
if (isset($_SESSION['portal_account_id'])) {
    header("Location: portal-dashboard.php");
    exit;
}

$API_PATH = 'api/portal_auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EssenciaSmile | Patient Portal</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <link rel="stylesheet" href="assets/css/portal-login.css">
    <link rel="stylesheet" href="assets/css/portalLogin-navbar.css">
</head>
<body>

<!-- ── Top bar ───────────────────────────────────────────────────────── -->
<?php include("partials/portalLogin-navbar.php")?>

<!-- ── Page body ─────────────────────────────────────────────────────── -->
<div class="page-body">

    <!-- ── Left panel — dental background ───────────────────────────── -->
    <div class="left-panel">

        <!-- Overlay layers -->
        <div class="left-overlay"></div>
        <div class="left-dots"></div>

        <!-- Decorative rings -->
        <div class="deco-ring deco-ring-1"></div>
        <div class="deco-ring deco-ring-2"></div>
        <div class="deco-ring deco-ring-3"></div>

        <!-- Headline block -->
        <div class="left-content">
            <h2>Your dental&nbsp;health,<br>always within reach.</h2>
            <p>
                Book appointments, track your treatment history, and manage your dental
                care — all in one place, whenever you need it.
            </p>
        </div>

        <!-- Feature list -->
        <div class="left-features">
            <div class="feat-row">
                <div class="feat-icon"><i class="fa-solid fa-calendar-check"></i></div>
                <span>Easy online appointment scheduling</span>
            </div>
            <div class="feat-row">
                <div class="feat-icon"><i class="fa-solid fa-file-medical"></i></div>
                <span>Access your dental records anytime</span>
            </div>
            <div class="feat-row">
                <div class="feat-icon"><i class="fa-solid fa-receipt"></i></div>
                <span>View billing and payment history</span>
            </div>
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

                <button class="btn-primary" id="loginBtn" onclick="doLogin()">
                    <span class="spinner" id="loginSpinner"></span>
                    <span class="btn-text" id="loginBtnText">Sign In</span>
                </button>

                <div class="auth-divider"><span>or</span></div>

                <button class="btn-google" id="googleBtn" onclick="signInWithGoogle()">
                    <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
                        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.18 1.48-4.97 2.35-8.16 2.35-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
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
                    <div class="prog-line" id="progLine2"></div>
                    <div class="reg-prog-step" id="prog3">
                        <div class="prog-dot"><span>3</span></div>
                        <div class="prog-label">Medical</div>
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
                            <input type="text" id="reg_firstName" placeholder="Juan" autocomplete="given-name">
                            <div class="field-err" id="errRegFirst"></div>
                        </div>
                        <div class="field">
                            <label for="reg_lastName">Last Name</label>
                            <input type="text" id="reg_lastName" placeholder="Dela Cruz" autocomplete="family-name">
                            <div class="field-err" id="errRegLast"></div>
                        </div>
                    </div>

                    <div class="grid-mid">
                        <div class="field">
                            <label for="reg_middleName">Middle Name <span class="opt">(optional)</span></label>
                            <input type="text" id="reg_middleName" placeholder="Santos">
                        </div>
                        <div class="field">
                            <label for="reg_suffix">Suffix <span class="opt">(opt.)</span></label>
                            <select id="reg_suffix">
                                <option value="">None</option>
                                <option>Jr.</option><option>Sr.</option>
                                <option>II</option><option>III</option><option>IV</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="field">
                            <label for="reg_birthdate">Birthdate</label>
                            <input type="date" id="reg_birthdate" autocomplete="bday">
                            <div class="field-err" id="errRegBirth"></div>
                        </div>
                        <div class="field">
                            <label for="reg_age">Age</label>
                            <input type="text" id="reg_age" placeholder="Auto-calculated" readonly
                                   style="background:#f7f9fc; color:var(--muted); cursor:default;">
                        </div>
                    </div>

                    <div class="field">
                        <label>Gender</label>
                        <div class="gender-pills">
                            <label class="gender-pill">
                                <input type="radio" name="reg_gender" value="Male">
                                <span><i class="fa-solid fa-mars"></i> Male</span>
                            </label>
                            <label class="gender-pill">
                                <input type="radio" name="reg_gender" value="Female">
                                <span><i class="fa-solid fa-venus"></i> Female</span>
                            </label>
                        </div>
                        <div class="field-err" id="errRegGender"></div>
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
                        <label for="reg_email">Email Address</label>
                        <input type="email" id="reg_email" placeholder="you@email.com" autocomplete="email">
                        <div class="field-err" id="errRegEmail"></div>
                    </div>

                    <div class="field">
                        <label for="reg_address">Home Address</label>
                        <input type="text" id="reg_address" placeholder="House No., Street, Barangay, District"
                               autocomplete="street-address">
                        <div class="field-err" id="errRegAddress"></div>
                    </div>

                    <div class="grid-2">
                        <div class="field">
                            <label>Province</label>
                            <div class="custom-select" id="provinceSelect">
                                <div class="cs-trigger" onclick="toggleCustomSelect('provinceSelect')">
                                    <span class="cs-value" id="provinceValue">Select province</span>
                                    <i class="fa-solid fa-chevron-down cs-arrow"></i>
                                </div>
                                <div class="cs-dropdown" id="provinceDropdown">
                                    <div class="cs-search-wrap">
                                        <i class="fa-solid fa-magnifying-glass"></i>
                                        <input type="text" class="cs-search" placeholder="Search province..." oninput="filterCustomSelect(this,'provinceDropdown')">
                                    </div>
                                    <ul class="cs-list" id="provinceList"></ul>
                                </div>
                            </div>
                            <input type="hidden" id="reg_province">
                            <div class="field-err" id="errRegProvince"></div>
                        </div>
                        <div class="field">
                            <label>City / Municipality</label>
                            <div class="custom-select" id="citySelect">
                                <div class="cs-trigger" onclick="toggleCustomSelect('citySelect')">
                                    <span class="cs-value" id="cityValue">Select province first</span>
                                    <i class="fa-solid fa-chevron-down cs-arrow"></i>
                                </div>
                                <div class="cs-dropdown" id="cityDropdown">
                                    <ul class="cs-list" id="cityList"></ul>
                                </div>
                            </div>
                            <input type="hidden" id="reg_city">
                            <div class="field-err" id="errRegCity"></div>
                        </div>
                    </div>

                    <div class="field" style="max-width:160px;">
                        <label for="reg_zip">ZIP Code</label>
                        <input type="text" id="reg_zip" placeholder="0000" maxlength="4"
                               inputmode="numeric">
                        <div class="field-err" id="errRegZip"></div>
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

                    <div class="step-nav">
                        <button class="btn-step-back" type="button" onclick="regPrevStep(2)">
                            <i class="fa-solid fa-arrow-left"></i> Back
                        </button>
                        <button class="btn-primary step-btn-next" type="button" onclick="regNextStep(2)">
                            Next <i class="fa-solid fa-arrow-right"></i>
                        </button>
                    </div>

                </div><!-- /regStep2 -->

                <!-- ════════════════════════════════
                     STEP 3 — Medical Questions
                     ════════════════════════════════ -->
                <div id="regStep3" class="reg-step-panel">

                    <div class="step-section-title">
                        <i class="fa-solid fa-heart-pulse"></i> Medical History
                    </div>

                    <p class="med-intro">Please answer the following honestly. This helps us provide safer dental care.</p>

                    <div class="yn-list">

                        <div class="yn-row">
                            <span class="yn-question">Are you in good health?</span>
                            <div class="yn-btns">
                                <button type="button" class="yn-btn" data-field="q_good_health" data-val="yes">Yes</button>
                                <button type="button" class="yn-btn" data-field="q_good_health" data-val="no">No</button>
                            </div>
                        </div>

                        <div class="yn-row">
                            <span class="yn-question">Are you under medical treatment now?</span>
                            <div class="yn-btns">
                                <button type="button" class="yn-btn" data-field="q_under_treatment" data-val="yes">Yes</button>
                                <button type="button" class="yn-btn" data-field="q_under_treatment" data-val="no">No</button>
                            </div>
                        </div>

                        <div class="yn-row">
                            <span class="yn-question">Have you ever had a serious illness or surgical operation?</span>
                            <div class="yn-btns">
                                <button type="button" class="yn-btn" data-field="q_serious_illness" data-val="yes">Yes</button>
                                <button type="button" class="yn-btn" data-field="q_serious_illness" data-val="no">No</button>
                            </div>
                        </div>

                        <div class="yn-row">
                            <span class="yn-question">Have you ever been hospitalized?</span>
                            <div class="yn-btns">
                                <button type="button" class="yn-btn" data-field="q_hospitalized" data-val="yes">Yes</button>
                                <button type="button" class="yn-btn" data-field="q_hospitalized" data-val="no">No</button>
                            </div>
                        </div>

                        <div class="yn-row">
                            <span class="yn-question">Are you taking any prescription or non-prescription medication?</span>
                            <div class="yn-btns">
                                <button type="button" class="yn-btn" data-field="q_taking_meds" data-val="yes">Yes</button>
                                <button type="button" class="yn-btn" data-field="q_taking_meds" data-val="no">No</button>
                            </div>
                        </div>

                        <div class="yn-row">
                            <span class="yn-question">Do you use tobacco products?</span>
                            <div class="yn-btns">
                                <button type="button" class="yn-btn" data-field="q_tobacco" data-val="yes">Yes</button>
                                <button type="button" class="yn-btn" data-field="q_tobacco" data-val="no">No</button>
                            </div>
                        </div>

                        <div class="yn-row">
                            <span class="yn-question">Do you use alcohol, cocaine, or other dangerous drugs?</span>
                            <div class="yn-btns">
                                <button type="button" class="yn-btn" data-field="q_dangerous_drugs" data-val="yes">Yes</button>
                                <button type="button" class="yn-btn" data-field="q_dangerous_drugs" data-val="no">No</button>
                            </div>
                        </div>

                    </div><!-- /yn-list -->

                    <div class="step-nav" style="margin-top:1.4rem;">
                        <button class="btn-step-back" type="button" onclick="regPrevStep(3)">
                            <i class="fa-solid fa-arrow-left"></i> Back
                        </button>
                        <button class="btn-primary step-btn-next" id="regBtn" onclick="submitRegistration()">
                            <span class="spinner" id="regSpinner"></span>
                            <span class="btn-text" id="regBtnText">Create Account</span>
                        </button>
                    </div>

                </div><!-- /regStep3 -->

            </div><!-- /registerPanel -->

        </div><!-- /auth-card -->
    </div><!-- /right-panel -->

</div><!-- /page-body -->

<script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ── API path ─────────────────────────────────────────────────────────
const API = '<?= $API_PATH ?>';

// ── Supabase client ──────────────────────────────────────────────────
const SUPABASE_URL  = 'https://fgltarvzzreozvtjiefy.supabase.co';
const SUPABASE_ANON = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImZnbHRhcnZ6enJlb3p2dGppZWZ5Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzE0MzI2NTIsImV4cCI6MjA4NzAwODY1Mn0.zfXhzMfINiFhqH4O_JAtaWh0j5GPrHd-zLyviROZAao';

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


// ── Tab switch ───────────────────────────────────────────────────────
function switchTab(tab) {
    const isLogin = tab === 'login';
    document.getElementById('tabLogin').classList.toggle('active', isLogin);
    document.getElementById('tabRegister').classList.toggle('active', !isLogin);
    document.getElementById('loginPanel').classList.toggle('active', isLogin);
    document.getElementById('registerPanel').classList.toggle('active', !isLogin);
    document.getElementById('authHeading').textContent = isLogin ? 'Welcome back' : 'Create your account';
    document.getElementById('authSub').textContent = isLogin
        ? 'Sign in to access your dental records and appointments.'
        : 'Register to schedule appointments and manage your care.';
    clearErrors();
}

// ── Password toggle ──────────────────────────────────────────────────
function togglePass(id, btn) {
    const inp = document.getElementById(id);
    const show = inp.type === 'text';
    inp.type = show ? 'password' : 'text';
    btn.querySelector('i').className = show ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
}

// ── Error helpers ────────────────────────────────────────────────────
function clearErrors() {
    document.querySelectorAll('.field-err').forEach(e => { e.style.display = 'none'; e.textContent = ''; });
    document.querySelectorAll('.alert-bar').forEach(e => { e.style.display = 'none'; e.className = 'alert-bar'; });
}
function showErr(id, msg) {
    const el = document.getElementById(id);
    if (el) { el.textContent = msg; el.style.display = 'block'; }
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
    const sp  = document.getElementById(spinnerId);
    const tx  = document.getElementById(textId);
    if (!btn) return;
    btn.disabled = loading;
    if (sp) sp.style.display = loading ? 'block' : 'none';
    if (tx) tx.textContent   = loading ? label : tx.dataset.orig || tx.textContent;
    if (loading && tx) tx.dataset.orig = tx.textContent;
}

// ── LOGIN ────────────────────────────────────────────────────────────
async function doLogin() {
    clearErrors();
    const email    = document.getElementById('loginEmail').value.trim();
    const password = document.getElementById('loginPassword').value;

    let ok = true;
    if (!email)    { showErr('errLoginEmail',    'Email is required.');    ok = false; }
    if (!password) { showErr('errLoginPassword', 'Password is required.'); ok = false; }
    if (!ok) return;

    setLoading('loginBtn', 'loginSpinner', 'loginBtnText', 'Signing in…', true);

    try {
        const res = await fetch(API, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'login', email, password }),
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
            showAlert('loginAlert', 'Please verify your email before logging in.');
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

// ── REGISTER — STEP NAVIGATION ──────────────────────────────────────
let currentRegStep = 1;

function regGoToStep(n) {
    [1, 2, 3].forEach(i => {
        document.getElementById('regStep' + i).classList.toggle('active', i === n);
        const prog = document.getElementById('prog' + i);
        prog.classList.toggle('active',    i <= n);
        prog.classList.toggle('completed', i <  n);
        const line = document.getElementById('progLine' + i);
        if (line) line.classList.toggle('filled', i < n);
    });
    currentRegStep = n;
    document.getElementById('registerPanel').scrollTop = 0;
}

function regNextStep(from) {
    clearErrors();
    if (from === 1) {
        const firstName = document.getElementById('reg_firstName').value.trim();
        const lastName  = document.getElementById('reg_lastName').value.trim();
        const birthdate = document.getElementById('reg_birthdate').value;
        const gender    = document.querySelector('input[name="reg_gender"]:checked');
        let ok = true;
        if (!firstName) { showErr('errRegFirst',  'First name is required.');  ok = false; }
        if (!lastName)  { showErr('errRegLast',   'Last name is required.');   ok = false; }
        if (!birthdate) { showErr('errRegBirth',  'Birthdate is required.');   ok = false; }
        if (!gender)    { showErr('errRegGender', 'Please select a gender.');  ok = false; }
        if (!ok) return;
    }
    if (from === 2) {
        const contact  = document.getElementById('reg_contact').value.trim();
        const email    = document.getElementById('reg_email').value.trim();
        const address  = document.getElementById('reg_address').value.trim();
        const province = document.getElementById('reg_province').value;
        const city     = document.getElementById('reg_city').value;
        const zip      = document.getElementById('reg_zip').value.trim();
        const password = document.getElementById('reg_password').value;
        const confirm  = document.getElementById('reg_confirm').value;
        let ok = true;
        if (!contact || !/^9\d{9}$/.test(contact))               { showErr('errRegContact',  'Enter a valid 10-digit PH number starting with 9.'); ok = false; }
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showErr('errRegEmail',    'Enter a valid email address.');  ok = false; }
        if (!address)                                               { showErr('errRegAddress',  'Home address is required.');      ok = false; }
        if (!province)                                              { showErr('errRegProvince', 'Please select a province.');      ok = false; }
        if (!city)                                                  { showErr('errRegCity',     'Please select a city.');          ok = false; }
        if (!zip || !/^\d{4}$/.test(zip))                         { showErr('errRegZip',      'Enter a valid 4-digit ZIP.');     ok = false; }
        if (password.length < 8)                                   { showErr('errRegPass',     'Password must be 8+ characters.'); ok = false; }
        if (password !== confirm)                                  { showErr('errRegConfirm',  'Passwords do not match.');        ok = false; }
        if (!ok) return;
    }
    regGoToStep(from + 1);
}

function regPrevStep(from) { regGoToStep(from - 1); }

// ── AGE AUTO-CALCULATE ────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('reg_birthdate').addEventListener('change', function () {
        const dob = new Date(this.value);
        if (isNaN(dob)) { document.getElementById('reg_age').value = ''; return; }
        const today = new Date();
        let age = today.getFullYear() - dob.getFullYear();
        const m = today.getMonth() - dob.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
        document.getElementById('reg_age').value = age >= 0 ? age + ' yrs old' : '';
    });

    // ── YES / NO button toggle ────────────────────────────────────────
    document.querySelectorAll('.yn-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const field = this.dataset.field;
            document.querySelectorAll(`.yn-btn[data-field="${field}"]`).forEach(b => b.classList.remove('yn-yes', 'yn-no'));
            this.classList.add(this.dataset.val === 'yes' ? 'yn-yes' : 'yn-no');
        });
    });
});

// ── CUSTOM SELECT — province & city ──────────────────────────────────
const PROVINCES = [
    'Metro Manila (NCR)','Abra','Agusan del Norte','Agusan del Sur','Aklan','Albay',
    'Antique','Apayao','Aurora','Basilan','Bataan','Batanes','Batangas','Benguet',
    'Biliran','Bohol','Bukidnon','Bulacan','Cagayan','Camarines Norte','Camarines Sur',
    'Camiguin','Capiz','Catanduanes','Cavite','Cebu','Cotabato','Davao de Oro',
    'Davao del Norte','Davao del Sur','Davao Occidental','Davao Oriental','Dinagat Islands',
    'Eastern Samar','Guimaras','Ifugao','Ilocos Norte','Ilocos Sur','Iloilo','Isabela',
    'Kalinga','La Union','Laguna','Lanao del Norte','Lanao del Sur','Leyte',
    'Maguindanao del Norte','Maguindanao del Sur','Marinduque','Masbate',
    'Misamis Occidental','Misamis Oriental','Mountain Province','Negros Occidental',
    'Negros Oriental','Northern Samar','Nueva Ecija','Nueva Vizcaya','Occidental Mindoro',
    'Oriental Mindoro','Palawan','Pampanga','Pangasinan','Quezon','Quirino','Rizal',
    'Romblon','Samar','Sarangani','Siquijor','Sorsogon','South Cotabato','Southern Leyte',
    'Sultan Kudarat','Sulu','Surigao del Norte','Surigao del Sur','Tarlac','Tawi-Tawi',
    'Zambales','Zamboanga del Norte','Zamboanga del Sur','Zamboanga Sibugay'
];

const CITY_MAP = {
    'Metro Manila (NCR)': ['Caloocan','Las Piñas','Makati','Malabon','Mandaluyong','Manila','Marikina','Muntinlupa','Navotas','Parañaque','Pasay','Pasig','Pateros','Quezon City','San Juan','Taguig','Valenzuela'],
    'Bulacan':  ['Malolos','Meycauayan','San Jose del Monte','Marilao','Bocaue','Balagtas','Baliuag','Plaridel','Santa Maria','Obando'],
    'Cavite':   ['Bacoor','Dasmariñas','General Trias','Imus','Kawit','Noveleta','Rosario','Tagaytay','Tanza','Trece Martires'],
    'Laguna':   ['Biñan','Cabuyao','Calamba','San Pablo','Santa Cruz','San Pedro','Sta. Rosa','Los Baños'],
    'Rizal':    ['Antipolo','Angono','Baras','Binangonan','Cainta','Cardona','Jala-Jala','Morong','Pililla','Rodriguez','San Mateo','Tanay','Taytay'],
    'Cebu':     ['Cebu City','Lapu-Lapu','Mandaue','Talisay','Toledo','Danao','Carcar','Bogo','Naga'],
    'Davao del Sur': ['Davao City','Digos'],
    'Pampanga': ['San Fernando','Angeles','Mabalacat','Porac','Guagua'],
    'Batangas': ['Batangas City','Lipa','Tanauan','Nasugbu','Sto. Tomas'],
    'Iloilo':   ['Iloilo City','Passi','Oton','Pavia','Santa Barbara'],
};

function buildCustomList(ulId, items, onSelect) {
    const ul = document.getElementById(ulId);
    ul.innerHTML = items.map(item =>
        `<li onclick="(${onSelect.toString()})('${item.replace(/'/g,"\'")}', this)">${item}</li>`
    ).join('');
}

function toggleCustomSelect(id) {
    const wrap = document.getElementById(id);
    const isOpen = wrap.classList.contains('open');
    // Close all open dropdowns first
    document.querySelectorAll('.custom-select.open').forEach(el => {
        el.classList.remove('open');
    });
    if (!isOpen) {
        wrap.classList.add('open');
        // Focus search if available
        const search = wrap.querySelector('.cs-search');
        if (search) { search.value = ''; filterCustomSelect(search, id + 'Dropdown'); search.focus(); }
    }
}

function filterCustomSelect(input, dropdownId) {
    const q = input.value.toLowerCase();
    const lis = document.querySelectorAll('#' + dropdownId + ' .cs-list li');
    lis.forEach(li => {
        li.style.display = li.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

function selectProvince(value, li) {
    document.getElementById('reg_province').value = value;
    document.getElementById('provinceValue').textContent = value;
    document.getElementById('provinceValue').classList.add('cs-selected');
    document.getElementById('provinceSelect').classList.remove('open');
    // Highlight selected
    li.closest('ul').querySelectorAll('li').forEach(l => l.classList.remove('active'));
    li.classList.add('active');
    // Populate cities
    const cities = CITY_MAP[value] || [];
    document.getElementById('reg_city').value = '';
    document.getElementById('cityValue').textContent = cities.length ? 'Select city' : 'No cities listed';
    document.getElementById('cityValue').classList.remove('cs-selected');
    buildCustomList('cityList', cities, selectCity);
}

function selectCity(value, li) {
    document.getElementById('reg_city').value = value;
    document.getElementById('cityValue').textContent = value;
    document.getElementById('cityValue').classList.add('cs-selected');
    document.getElementById('citySelect').classList.remove('open');
    li.closest('ul').querySelectorAll('li').forEach(l => l.classList.remove('active'));
    li.classList.add('active');
}

// Close dropdowns when clicking outside
document.addEventListener('click', e => {
    if (!e.target.closest('.custom-select')) {
        document.querySelectorAll('.custom-select.open').forEach(el => el.classList.remove('open'));
    }
});

// Build province list on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    buildCustomList('provinceList', PROVINCES, selectProvince);
});

// ── REGISTER — SUBMIT ─────────────────────────────────────────────────
async function submitRegistration() {
    clearErrors();
    setLoading('regBtn', 'regSpinner', 'regBtnText', 'Creating account…', true);

    const getYN = f => document.querySelector(`.yn-btn.yn-yes[data-field="${f}"], .yn-btn.yn-no[data-field="${f}"]`)?.dataset.val || null;

    try {
        const res = await fetch(API, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action:            'register',
                first_name:        document.getElementById('reg_firstName').value.trim(),
                last_name:         document.getElementById('reg_lastName').value.trim(),
                middle_name:       document.getElementById('reg_middleName').value.trim(),
                suffix:            document.getElementById('reg_suffix').value,
                birthdate:         document.getElementById('reg_birthdate').value,
                gender:            document.querySelector('input[name="reg_gender"]:checked')?.value || '',
                contact_number:    '+63' + document.getElementById('reg_contact').value.trim(),
                home_phone:        document.getElementById('reg_homePhone').value.trim(),
                email:             document.getElementById('reg_email').value.trim(),
                password:          document.getElementById('reg_password').value,
                address:           document.getElementById('reg_address').value.trim(),
                city:              document.getElementById('reg_city').value,
                province:          document.getElementById('reg_province').value,
                zip:               document.getElementById('reg_zip').value.trim(),
                q_good_health:     getYN('q_good_health'),
                q_under_treatment: getYN('q_under_treatment'),
                q_serious_illness: getYN('q_serious_illness'),
                q_hospitalized:    getYN('q_hospitalized'),
                q_taking_meds:     getYN('q_taking_meds'),
                q_tobacco:         getYN('q_tobacco'),
                q_dangerous_drugs: getYN('q_dangerous_drugs'),
            }),
        });

        if (!res.ok) {
            showAlert('registerAlert', `Server error (${res.status}). Check console.`);
            return;
        }

        let data;
        try { data = await res.json(); }
        catch (e) { showAlert('registerAlert', 'Server returned an unexpected response.'); return; }

        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Account Created!',
                text: 'Your account is now active. You can sign in.',
                confirmButtonColor: '#1a56db',
            }).then(() => { regGoToStep(1); switchTab('login'); });
        } else {
            if (data.message?.includes('email already exists')) {
                regGoToStep(2);
                showErr('errRegEmail', 'You already have an account with this email. Try signing in.');
            } else {
                showAlert('registerAlert', data.message || 'Registration failed. Please try again.');
            }
        }
    } catch (err) {
        console.error('Register fetch error:', err);
        showAlert('registerAlert', 'Could not reach the server. Make sure XAMPP is running.');
    } finally {
        setLoading('regBtn', 'regSpinner', 'regBtnText', '', false);
        document.getElementById('regBtnText').textContent = 'Create Account';
    }
}

// ── SIGN IN WITH GOOGLE ───────────────────────────────────────────────
async function signInWithGoogle() {
    const btn = document.getElementById('googleBtn');
    btn.disabled = true;
    btn.classList.add('loading');

    const { error } = await getSupabase().auth.signInWithOAuth({
        provider: 'google',
        options: {
            redirectTo: window.location.origin + '/portal-auth-callback.php',
        }
    });

    if (error) {
        btn.disabled = false;
        btn.classList.remove('loading');
        showAlert('loginAlert', 'Google sign-in failed: ' + error.message);
    }
    // On success, Supabase redirects the browser — no further action needed here
}

// ── Enter-key convenience ─────────────────────────────────────────────
document.addEventListener('keydown', e => {
    if (e.key !== 'Enter') return;
    if (document.getElementById('loginPanel').classList.contains('active')) doLogin();
});
</script>
</body>
</html>