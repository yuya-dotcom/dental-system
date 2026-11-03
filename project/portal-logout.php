<?php
session_start();

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

$reason = $_GET['reason'] ?? '';
$target = 'portal-login.php' . ($reason ? '?expired=' . urlencode($reason) : '');

// Tell every other open portal tab (e.g. portal-web-scheduling.php) that this
// account signed out, then continue to the login page. Every logout path —
// Sign Out button, idle timeout, max-duration timeout — comes through here.
header('Cache-Control: no-store');
$t = htmlspecialchars($target, ENT_QUOTES);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Signing out…</title>
    <noscript><meta http-equiv="refresh" content="0;url=<?= $t ?>"></noscript>
</head>
<body>
<script>
    try { new BroadcastChannel('essencia-portal').postMessage({ type: 'logout' }); } catch (e) {}
    try { localStorage.setItem('essencia-portal-logout', String(Date.now())); } catch (e) {}
    location.replace(<?= json_encode($target) ?>);
</script>
</body>
</html>
<?php
exit;

