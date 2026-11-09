<?php
// portal-google-callback.php
// ─────────────────────────────────────────────────────────────
// Supabase redirects the patient here after Google OAuth.
// JS picks up the session token, sends user info to PHP,
// PHP sets the portal session, then redirects to dashboard.
// ─────────────────────────────────────────────────────────────
session_start();
if (isset($_SESSION['portal_account_id'])) {
    header("Location: portal-dashboard.php");
    exit;
}

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
    <title>EssenciaSmile | Signing you in…</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .card {
            background: #fff;
            border-radius: 18px;
            padding: 48px 40px;
            box-shadow: 0 4px 32px rgba(0, 0, 0, .08);
            text-align: center;
            max-width: 380px;
            width: 100%;
        }

        .logo {
            width: 52px;
            height: 52px;
            background: #e8f0fe;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 24px;
        }

        h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 8px;
        }

        p {
            font-size: 0.875rem;
            color: #718096;
            margin-bottom: 28px;
            line-height: 1.6;
        }

        /* Spinner */
        .spinner-wrap {
            display: flex;
            justify-content: center;
            margin-bottom: 24px;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #e2e8f0;
            border-top-color: #4285F4;
            border-radius: 50%;
            animation: spin .8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Error state */
        .error-box {
            display: none;
            background: #fff5f5;
            border: 1px solid #fed7d7;
            border-radius: 10px;
            padding: 14px 18px;
            color: #c53030;
            font-size: 0.83rem;
            margin-bottom: 20px;
            text-align: left;
            line-height: 1.5;
        }

        .btn-back {
            display: inline-block;
            padding: 10px 24px;
            background: #1a202c;
            color: #fff;
            border-radius: 10px;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            display: none;
        }

        /* Step indicator */
        .step-text {
            font-size: 0.78rem;
            color: #a0aec0;
            margin-top: 4px;
        }
    </style>
</head>

<body>

    <div class="card">
        <div class="logo">🦷</div>
        <h2 id="heading">Completing sign-in…</h2>
        <p id="subtext">Please wait while we verify your Google account.</p>

        <div class="spinner-wrap" id="spinnerWrap">
            <div class="spinner"></div>
        </div>

        <div class="error-box" id="errorBox"></div>
        <p class="step-text" id="stepText">Connecting to Google…</p>

        <a href="portal-login.php" class="btn-back" id="backBtn">← Back to Sign In</a>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
    <script>
        // ── Config (mirrors portal-login.php) ───────────────────────
        const SUPABASE_URL = <?= json_encode($SUPABASE_PUBLIC_URL, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>;
        const SUPABASE_ANON = <?= json_encode($SUPABASE_PUBLISHABLE_KEY, JSON_HEX_TAG) ?>; // sb_publishable_ key

        const client = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON);

        // ── UI helpers ───────────────────────────────────────────────
        function setStep(msg) {
            document.getElementById('stepText').textContent = msg;
        }

        function showError(msg) {
            document.getElementById('spinnerWrap').style.display = 'none';
            document.getElementById('heading').textContent = 'Sign-in failed';
            document.getElementById('subtext').textContent = 'Something went wrong. You can try again from the login page.';
            const box = document.getElementById('errorBox');
            box.textContent = msg;
            box.style.display = 'block';
            document.getElementById('backBtn').style.display = 'inline-block';
            document.getElementById('stepText').style.display = 'none';
        }

        // ── Main callback handler ────────────────────────────────────
        async function handleGoogleCallback() {
            try {
                // Step 1: Retrieve the session Supabase set after OAuth redirect.
                // The SDK automatically reads the token from the URL hash/query.
                setStep('Reading Google session…');
                const {
                    data: {
                        session
                    },
                    error: sessionErr
                } = await client.auth.getSession();

                if (sessionErr || !session) {
                    // No session yet — try exchanging the code (PKCE flow)
                    const params = new URLSearchParams(window.location.search);
                    const code = params.get('code');

                    if (code) {
                        setStep('Exchanging auth code…');
                        const {
                            data: exchanged,
                            error: exchErr
                        } = await client.auth.exchangeCodeForSession(code);
                        if (exchErr || !exchanged?.session) {
                            throw new Error(exchErr?.message || 'Could not exchange auth code for session.');
                        }
                    } else {
                        throw new Error(sessionErr?.message || 'No Google session found. The sign-in may have been cancelled or expired.');
                    }
                }

                // Step 2: Re-fetch session after possible code exchange
                setStep('Verifying identity…');
                const {
                    data: {
                        session: finalSession
                    }
                } = await client.auth.getSession();
                if (!finalSession) throw new Error('Session could not be established.');

                const user = finalSession.user;
                if (!user?.email) throw new Error('Google did not return an email address. Please check your Google account settings.');

                // Step 3: Send to PHP to find or create portal account + set PHP session
                setStep('Setting up your account…');
                const res = await fetch('api/portal_auth.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'google_auth',
                        // The server verifies this token with Supabase Auth and takes the email from it
                        access_token: finalSession.access_token,
                        email: user.email,
                        full_name: user.user_metadata?.full_name || user.user_metadata?.name || '',
                        google_id: user.id,
                    }),
                });

                if (!res.ok) {
                    const raw = await res.text();
                    console.error('portal_auth.php error:', res.status, raw);
                    throw new Error(`Server error (${res.status}). Check the console for details.`);
                }

                const data = await res.json();
                if (!data.success) throw new Error(data.message || 'Account setup failed.');

                // Step 4: All good — go to dashboard
                setStep('Redirecting…');
                window.opener && window.opener.postMessage('google_auth_success', '*');
                window.close();

            } catch (err) {
                console.error('[Google Callback]', err);
                showError(err.message);
            }
        }

        handleGoogleCallback();
    </script>
</body>

</html>