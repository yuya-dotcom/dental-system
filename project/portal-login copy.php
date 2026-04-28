<?php
// portal/portal-login.php
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
    <link rel="stylesheet" href="assets/css/portal-login copy.css">
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
            <div class="feat-row">
                <div class="feat-icon"><i class="fa-solid fa-shield-halved"></i></div>
                <span>Secure, HIPAA-compliant patient portal</span>
            </div>
        </div>

        <!-- Stats footer -->
        <div class="left-footer">
            <div class="stat-col">
                <span class="stat-num">12k+</span>
                <span class="stat-label">Patients</span>
            </div>
            <div class="stat-col">
                <span class="stat-num">98%</span>
                <span class="stat-label">Satisfaction</span>
            </div>
            <div class="stat-col">
                <span class="stat-num">24/7</span>
                <span class="stat-label">Access</span>
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

                <p class="foot-note">
                    Don't have an account?
                    <button class="foot-link" onclick="switchTab('register')">Create an account</button>
                </p>

            </div><!-- /loginPanel -->

            <!-- ── REGISTER PANEL ── -->
            <div id="registerPanel" class="form-panel">

                <div id="registerAlert" class="alert-bar"></div>

                <div id="regStep1">

                    <div class="grid-2">
                        <div class="field">
                            <label for="reg_firstName">First Name <span class="req">*</span></label>
                            <input type="text" id="reg_firstName"
                                   placeholder="Juan" autocomplete="given-name">
                            <div class="field-err" id="errRegFirst"></div>
                        </div>
                        <div class="field">
                            <label for="reg_lastName">Last Name <span class="req">*</span></label>
                            <input type="text" id="reg_lastName"
                                   placeholder="Dela Cruz" autocomplete="family-name">
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

                    <div class="field">
                        <label for="reg_birthdate">Birthdate <span class="req">*</span></label>
                        <input type="date" id="reg_birthdate" autocomplete="bday">
                        <div class="field-err" id="errRegBirth"></div>
                    </div>

                    <!-- Email with inline Send OTP -->
                    <div class="field">
                        <label for="reg_email">Email Address <span class="req">*</span></label>
                        <div class="email-otp-wrap">
                            <input type="email" id="reg_email"
                                   placeholder="you@email.com" autocomplete="email">
                            <button class="btn-send-otp" type="button"
                                    id="sendEmailOtpBtn" onclick="sendEmailOTP()">
                                Send OTP
                            </button>
                        </div>
                        <div class="field-err" id="errRegEmail"></div>
                    </div>

                    <!-- Email OTP entry — hidden until OTP is sent -->
                    <div id="emailOtpSection" class="field" style="display:none;">
                        <label>Enter the 6-digit code sent to your email</label>
                        <div class="otp-row" style="justify-content:flex-start; margin:.5rem 0 .4rem;">
                            <input class="otp-box" maxlength="1" id="eotp1" inputmode="numeric"
                                   oninput="otpNext(this,'eotp2')" onkeydown="otpBack(event,this,'')">
                            <input class="otp-box" maxlength="1" id="eotp2" inputmode="numeric"
                                   oninput="otpNext(this,'eotp3')" onkeydown="otpBack(event,this,'eotp1')">
                            <input class="otp-box" maxlength="1" id="eotp3" inputmode="numeric"
                                   oninput="otpNext(this,'eotp4')" onkeydown="otpBack(event,this,'eotp2')">
                            <input class="otp-box" maxlength="1" id="eotp4" inputmode="numeric"
                                   oninput="otpNext(this,'eotp5')" onkeydown="otpBack(event,this,'eotp3')">
                            <input class="otp-box" maxlength="1" id="eotp5" inputmode="numeric"
                                   oninput="otpNext(this,'eotp6')" onkeydown="otpBack(event,this,'eotp4')">
                            <input class="otp-box" maxlength="1" id="eotp6" inputmode="numeric"
                                   oninput="otpNext(this,'')"    onkeydown="otpBack(event,this,'eotp5')">
                        </div>
                        <div class="otp-err" id="emailOtpErr" style="text-align:left; margin-bottom:.4rem;"></div>
                        <div style="display:flex; gap:.6rem; align-items:center;">
                            <button class="btn-primary" type="button" id="verifyEmailBtn"
                                    onclick="verifyEmailOTP()"
                                    style="margin-top:0; flex:1; padding:.6rem 1rem;">
                                <span class="btn-text">Verify Email</span>
                            </button>
                            <button class="foot-link" type="button" onclick="sendEmailOTP()">Resend</button>
                        </div>
                        <div id="emailVerifiedBadge" style="display:none; color:var(--success);
                             font-size:.82rem; font-weight:600; margin-top:.5rem;">
                            <i class="fa-solid fa-circle-check"></i> Email verified
                        </div>
                    </div>

                    <div class="field">
                        <label for="reg_password">Password <span class="req">*</span></label>
                        <div class="pass-wrap">
                            <input type="password" id="reg_password"
                                   placeholder="Min. 8 characters"
                                   autocomplete="new-password">
                            <button class="pass-eye" type="button"
                                    onclick="togglePass('reg_password',this)"
                                    tabindex="-1" aria-label="Toggle password">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                        <div class="field-err" id="errRegPass"></div>
                    </div>

                    <div class="field">
                        <label for="reg_confirm">Confirm Password <span class="req">*</span></label>
                        <div class="pass-wrap">
                            <input type="password" id="reg_confirm"
                                   placeholder="Re-enter password"
                                   autocomplete="new-password">
                            <button class="pass-eye" type="button"
                                    onclick="togglePass('reg_confirm',this)"
                                    tabindex="-1" aria-label="Toggle password">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                        <div class="field-err" id="errRegConfirm"></div>
                    </div>

                    <button class="btn-primary" id="regBtn" onclick="submitRegistration()">
                        <span class="spinner" id="regSpinner"></span>
                        <span class="btn-text" id="regBtnText">Create Account</span>
                    </button>

                    <p class="foot-note">
                        Already have an account?
                        <button class="foot-link" onclick="switchTab('login')">Sign in</button>
                    </p>

                </div><!-- /regStep1 -->

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

// Supabase is initialized lazily — only when OTP is first needed
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

// Email verification state
let emailVerified = false;

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
    const oe = document.getElementById('emailOtpErr');
    if (oe) { oe.style.display = 'none'; oe.textContent = ''; }
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

// ── OTP box helpers (reused for email OTP boxes) ─────────────────────
function otpNext(el, nextId) {
    el.value = el.value.replace(/\D/g, '');
    if (el.value && nextId) document.getElementById(nextId)?.focus();
}
function otpBack(e, el, prevId) {
    if (e.key === 'Backspace' && !el.value && prevId) document.getElementById(prevId)?.focus();
}

// ── SEND EMAIL OTP ───────────────────────────────────────────────────
async function sendEmailOTP() {
    const email = document.getElementById('reg_email').value.trim();
    const errEl = document.getElementById('errRegEmail');

    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errEl.textContent = 'Enter a valid email address first.';
        errEl.style.display = 'block';
        return;
    }
    errEl.style.display = 'none';

    const btn = document.getElementById('sendEmailOtpBtn');
    btn.disabled = true;
    btn.textContent = 'Sending…';

    const { error } = await getSupabase().auth.signInWithOtp({
        email,
        options: { shouldCreateUser: true }
    });

    btn.disabled = false;
    btn.textContent = 'Resend OTP';

    if (error) {
        errEl.textContent = 'Could not send OTP: ' + error.message;
        errEl.style.display = 'block';
        return;
    }

    // Show the inline OTP entry section
    document.getElementById('emailOtpSection').style.display = 'block';
    document.getElementById('emailVerifiedBadge').style.display = 'none';
    document.getElementById('verifyEmailBtn').style.display = '';
    emailVerified = false;
    document.getElementById('eotp1').focus();
}

// ── VERIFY EMAIL OTP ─────────────────────────────────────────────────
async function verifyEmailOTP() {
    const email = document.getElementById('reg_email').value.trim();
    const token = ['eotp1','eotp2','eotp3','eotp4','eotp5','eotp6']
                    .map(id => document.getElementById(id).value).join('');
    const errEl = document.getElementById('emailOtpErr');

    if (token.length < 6) {
        errEl.textContent  = 'Please enter all 6 digits.';
        errEl.style.display = 'block';
        return;
    }
    errEl.style.display = 'none';

    const verifyBtn = document.getElementById('verifyEmailBtn');
    verifyBtn.disabled = true;

    const { error } = await getSupabase().auth.verifyOtp({
        email,
        token,
        type: 'signup'
    });

    verifyBtn.disabled = false;

    if (error) {
        errEl.textContent  = 'Invalid or expired code. Try again.';
        errEl.style.display = 'block';
        return;
    }

    // Mark as verified
    emailVerified = true;
    document.getElementById('emailVerifiedBadge').style.display = 'block';
    verifyBtn.style.display = 'none';
    document.getElementById('sendEmailOtpBtn').disabled = true;
    document.getElementById('reg_email').readOnly = true;
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

// ── REGISTER ─────────────────────────────────────────────────────────
async function submitRegistration() {
    clearErrors();

    const firstName = document.getElementById('reg_firstName').value.trim();
    const lastName  = document.getElementById('reg_lastName').value.trim();
    const birthdate = document.getElementById('reg_birthdate').value;
    const email     = document.getElementById('reg_email').value.trim();
    const password  = document.getElementById('reg_password').value;
    const confirm   = document.getElementById('reg_confirm').value;

    let ok = true;
    if (!firstName)                                           { showErr('errRegFirst',   'First name is required.');          ok = false; }
    if (!lastName)                                            { showErr('errRegLast',    'Last name is required.');           ok = false; }
    if (!birthdate)                                           { showErr('errRegBirth',   'Birthdate is required.');           ok = false; }
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showErr('errRegEmail',   'Enter a valid email address.');    ok = false; }
    if (!emailVerified)                                       { showErr('errRegEmail',   'Please verify your email first.'); ok = false; }
    if (password.length < 8)                                  { showErr('errRegPass',    'Password must be 8+ characters.'); ok = false; }
    if (password !== confirm)                                 { showErr('errRegConfirm', 'Passwords do not match.');         ok = false; }
    if (!ok) return;

    setLoading('regBtn', 'regSpinner', 'regBtnText', 'Creating account…', true);

    try {
        const res = await fetch(API, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                action:      'register',
                first_name:  firstName,
                last_name:   lastName,
                middle_name: document.getElementById('reg_middleName').value.trim(),
                suffix:      document.getElementById('reg_suffix').value,
                birthdate,
                email,
                password,
            }),
        });

        if (!res.ok) {
            const raw = await res.text();
            console.error('Register HTTP error', res.status, raw);
            showAlert('registerAlert', `Server error (${res.status}). Check console.`);
            return;
        }

        let data;
        try { data = await res.json(); }
        catch (e) {
            const raw = await res.text().catch(() => '');
            console.error('Register parse error — raw:', raw);
            showAlert('registerAlert', 'Server returned an unexpected response.');
            return;
        }

        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Account Created!',
                text: 'Your account is now active. You can sign in.',
                confirmButtonColor: '#1a56db',
            }).then(() => switchTab('login'));
        } else {
            showAlert('registerAlert', data.message || 'Registration failed. Please try again.');
        }
    } catch (err) {
        console.error('Register fetch error:', err);
        showAlert('registerAlert', 'Could not reach the server. Make sure XAMPP is running and the API path is correct: ' + API);
    } finally {
        setLoading('regBtn', 'regSpinner', 'regBtnText', '', false);
        document.getElementById('regBtnText').textContent = 'Create Account';
    }
}

// ── Enter-key convenience ─────────────────────────────────────────────
document.addEventListener('keydown', e => {
    if (e.key !== 'Enter') return;
    if (document.getElementById('loginPanel').classList.contains('active')) doLogin();
});
</script>
</body>
</html>