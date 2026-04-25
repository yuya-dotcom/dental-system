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

    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/portal-login.css">

</head>
<body>

<!-- ── Top bar ───────────────────────────────────────────────────── -->
<header class="topbar">
    <a class="topbar-brand" href="appointmentWeb-Landing.php">
        <img src="assets/images/Essencia-full@3x.png" alt="EssenciaSmile">
    </a>
    <a class="topbar-back" href="appointmentWeb-Landing.php">
        <i class="fa-solid fa-arrow-left"></i> Back to Home
    </a>
</header>

<!-- ── Page body ─────────────────────────────────────────────────── -->
<div class="page-body">

    <!-- ── Left panel ────────────────────────────────────────────── -->
    <div class="left-panel">

        <div class="left-content">
            <h2>Your dental&nbsp;health,<br>always within reach.</h2>
            <p>
                Book appointments, track your treatment history, and manage your dental
                care — all in one place, whenever you need it.
            </p>
        </div>

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
                <span>Secure, private patient portal</span>
            </div>
        </div>
    </div>

    <!-- ── Right panel (auth form) ───────────────────────────────── -->
    <div class="right-panel">
        <div class="auth-card">

            <div class="auth-header">
                <div class="portal-pill">
                    <i class="fa-solid fa-circle-user"></i>
                    Patient Portal
                </div>
                <h1 id="authHeading">Welcome back</h1>
                <p id="authSub">Sign in to access your dental records and appointments.</p>
            </div>

            <!-- Tabs -->
            <div class="tab-bar" role="tablist">
                <button class="tab-btn active" id="tabLogin"
                        onclick="switchTab('login')" role="tab"
                        aria-selected="true">Sign In</button>
                <button class="tab-btn" id="tabRegister"
                        onclick="switchTab('register')" role="tab"
                        aria-selected="false">Create Account</button>
            </div>

            <!-- ── LOGIN ── -->
            <div id="loginPanel" class="form-panel active">
                <div id="loginAlert" class="alert-bar"></div>

                <div class="field">
                    <label for="loginEmail">Email Address</label>
                    <input type="email" id="loginEmail" placeholder="you@email.com" autocomplete="email">
                    <div class="field-err" id="errLoginEmail"></div>
                </div>

                <div class="field">
                    <label for="loginPassword">Password</label>
                    <div class="pass-wrap">
                        <input type="password" id="loginPassword" placeholder="Enter your password" autocomplete="current-password">
                        <button class="pass-eye" type="button" onclick="togglePass('loginPassword',this)" tabindex="-1" aria-label="Toggle password">
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
                    <button class="foot-link" onclick="switchTab('register')">Create one free</button>
                </p>
            </div>

            <!-- ── REGISTER ── -->
            <div id="registerPanel" class="form-panel">
                <div id="registerAlert" class="alert-bar"></div>

                <!-- Step 1 — form fields -->
                <div id="regStep1">
                    <div class="grid-2">
                        <div class="field">
                            <label for="reg_firstName">First Name <span class="req">*</span></label>
                            <input type="text" id="reg_firstName" placeholder="Juan" autocomplete="given-name">
                            <div class="field-err" id="errRegFirst"></div>
                        </div>
                        <div class="field">
                            <label for="reg_lastName">Last Name <span class="req">*</span></label>
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

                    <div class="field">
                        <label for="reg_birthdate">Birthdate <span class="req">*</span></label>
                        <input type="date" id="reg_birthdate" autocomplete="bday">
                        <div class="field-err" id="errRegBirth"></div>
                    </div>

                    <div class="field">
                        <label for="reg_contact">Contact Number <span class="req">*</span></label>
                        <div class="phone-wrap">
                            <span class="phone-prefix">+63</span>
                            <input type="tel" id="reg_contact" placeholder="9XX XXX XXXX"
                                   maxlength="10" oninput="this.value=this.value.replace(/\D/g,'').substring(0,10)"
                                   autocomplete="tel-national">
                        </div>
                        <div class="field-err" id="errRegContact"></div>
                    </div>

                    <div class="field">
                        <label for="reg_email">Email Address <span class="req">*</span></label>
                        <input type="email" id="reg_email" placeholder="you@email.com" autocomplete="email">
                        <div class="field-err" id="errRegEmail"></div>
                    </div>

                    <div class="field">
                        <label for="reg_password">Password <span class="req">*</span></label>
                        <div class="pass-wrap">
                            <input type="password" id="reg_password" placeholder="Min. 8 characters" autocomplete="new-password">
                            <button class="pass-eye" type="button" onclick="togglePass('reg_password',this)" tabindex="-1" aria-label="Toggle password">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                        <div class="field-err" id="errRegPass"></div>
                    </div>

                    <div class="field">
                        <label for="reg_confirm">Confirm Password <span class="req">*</span></label>
                        <div class="pass-wrap">
                            <input type="password" id="reg_confirm" placeholder="Re-enter password" autocomplete="new-password">
                            <button class="pass-eye" type="button" onclick="togglePass('reg_confirm',this)" tabindex="-1" aria-label="Toggle password">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                        <div class="field-err" id="errRegConfirm"></div>
                    </div>

                    <button class="btn-primary" id="regBtn" onclick="submitRegistration()">
                        <span class="spinner" id="regSpinner"></span>
                        <span class="btn-text" id="regBtnText">Create Account &amp; Send OTP</span>
                    </button>

                    <p class="foot-note">
                        Already have an account?
                        <button class="foot-link" onclick="switchTab('login')">Sign in</button>
                    </p>
                </div>

                <!-- OTP Step -->
                <div id="otpPanel" class="otp-panel">
                    <p class="otp-hint">We sent a 6-digit OTP to your phone</p>
                    <p class="otp-hint"><strong id="otpContactDisplay">+63 9XX XXX XXXX</strong></p>

                    <div class="otp-row">
                        <input class="otp-box" maxlength="1" id="otp1" inputmode="numeric"
                               oninput="otpNext(this,'otp2')" onkeydown="otpBack(event,this,'')">
                        <input class="otp-box" maxlength="1" id="otp2" inputmode="numeric"
                               oninput="otpNext(this,'otp3')" onkeydown="otpBack(event,this,'otp1')">
                        <input class="otp-box" maxlength="1" id="otp3" inputmode="numeric"
                               oninput="otpNext(this,'otp4')" onkeydown="otpBack(event,this,'otp2')">
                        <input class="otp-box" maxlength="1" id="otp4" inputmode="numeric"
                               oninput="otpNext(this,'otp5')" onkeydown="otpBack(event,this,'otp3')">
                        <input class="otp-box" maxlength="1" id="otp5" inputmode="numeric"
                               oninput="otpNext(this,'otp6')" onkeydown="otpBack(event,this,'otp4')">
                        <input class="otp-box" maxlength="1" id="otp6" inputmode="numeric"
                               oninput="otpNext(this,'')"    onkeydown="otpBack(event,this,'otp5')">
                    </div>

                    <div class="otp-err" id="otpErr"></div>

                    <button class="btn-primary" onclick="verifyOTP()">
                        <span class="btn-text">Verify &amp; Activate Account</span>
                    </button>

                    <p class="foot-note" style="margin-top:.75rem;">
                        Didn't receive it?
                        <button class="foot-link" onclick="resendOTP()">Resend OTP</button>
                    </p>
                </div>
            </div><!-- /registerPanel -->

        </div><!-- /auth-card -->
    </div><!-- /right-panel -->

</div><!-- /page-body -->

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ── API path (injected from PHP) ─────────────────────────────────────
const API = '<?= $API_PATH ?>';

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
    const oe = document.getElementById('otpErr');
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
        const res  = await fetch(API, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'login', email, password }),
        });

        // ── Debug: surface non-200 responses clearly ──
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
    const contact   = document.getElementById('reg_contact').value.trim();
    const email     = document.getElementById('reg_email').value.trim();
    const password  = document.getElementById('reg_password').value;
    const confirm   = document.getElementById('reg_confirm').value;

    let ok = true;
    if (!firstName)                               { showErr('errRegFirst',   'First name is required.');           ok = false; }
    if (!lastName)                                { showErr('errRegLast',    'Last name is required.');            ok = false; }
    if (!birthdate)                               { showErr('errRegBirth',   'Birthdate is required.');            ok = false; }
    if (contact.length < 10)                      { showErr('errRegContact', 'Enter a valid 10-digit number.');   ok = false; }
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email))
                                                  { showErr('errRegEmail',   'Enter a valid email address.');     ok = false; }
    if (password.length < 8)                      { showErr('errRegPass',    'Password must be 8+ characters.');  ok = false; }
    if (password !== confirm)                     { showErr('errRegConfirm', 'Passwords do not match.');          ok = false; }
    if (!ok) return;

    setLoading('regBtn', 'regSpinner', 'regBtnText', 'Creating account…', true);

    try {
        const res = await fetch(API, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                action:         'register',
                first_name:     firstName,
                last_name:      lastName,
                middle_name:    document.getElementById('reg_middleName').value.trim(),
                suffix:         document.getElementById('reg_suffix').value,
                birthdate,
                contact_number: '+63' + contact,
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
            document.getElementById('otpContactDisplay').textContent = '+63 ' + contact;
            document.getElementById('regStep1').style.display  = 'none';
            document.getElementById('otpPanel').style.display  = 'block';
        } else {
            showAlert('registerAlert', data.message || 'Registration failed. Please try again.');
        }
    } catch (err) {
        console.error('Register fetch error:', err);
        showAlert('registerAlert', 'Could not reach the server. Make sure XAMPP is running and the API path is correct: ' + API);
    } finally {
        setLoading('regBtn', 'regSpinner', 'regBtnText', '', false);
        document.getElementById('regBtnText').textContent = 'Create Account & Send OTP';
    }
}

// ── OTP helpers ──────────────────────────────────────────────────────
function otpNext(el, nextId) {
    el.value = el.value.replace(/\D/g, '');
    if (el.value && nextId) document.getElementById(nextId)?.focus();
}
function otpBack(e, el, prevId) {
    if (e.key === 'Backspace' && !el.value && prevId) document.getElementById(prevId)?.focus();
}
function getOTP() {
    return ['otp1','otp2','otp3','otp4','otp5','otp6']
        .map(id => document.getElementById(id).value).join('');
}

async function verifyOTP() {
    const otp = getOTP();
    const otpErr = document.getElementById('otpErr');
    if (otp.length < 6) {
        otpErr.textContent = 'Please enter all 6 digits.';
        otpErr.style.display = 'block';
        return;
    }
    otpErr.style.display = 'none';

    try {
        const res  = await fetch(API, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                action: 'verify_otp',
                otp,
                email: document.getElementById('reg_email').value.trim(),
            }),
        });
        const data = await res.json();
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Account Verified!',
                text: 'Your account is now active. You can now sign in.',
                confirmButtonColor: '#1a56db',
            }).then(() => switchTab('login'));
        } else {
            otpErr.textContent  = data.message || 'Incorrect OTP. Please try again.';
            otpErr.style.display = 'block';
        }
    } catch (err) {
        otpErr.textContent  = 'Connection error. Please check your internet.';
        otpErr.style.display = 'block';
    }
}

async function resendOTP() {
    const email = document.getElementById('reg_email').value.trim();
    try {
        await fetch(API, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'resend_otp', email }),
        });
        Swal.fire({
            icon: 'info', title: 'OTP Sent',
            text: 'A new 6-digit code has been sent to your phone.',
            confirmButtonColor: '#1a56db', timer: 2800, showConfirmButton: false,
        });
    } catch (err) {
        console.error('Resend OTP error:', err);
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