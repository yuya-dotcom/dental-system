<?php
// Shared portal top bar (portal-login.php, portal-web-scheduling.php).
// Optional before include: $navBrandHref, $navBackHref, $navBackLabel.
$navBrandHref = $navBrandHref ?? 'portal-landing-page.php';
$navBackHref  = $navBackHref  ?? 'portal-landing-page.php';
$navBackLabel = $navBackLabel ?? 'Back to Home';
?>
<nav class="topbar">
    <a href="<?= htmlspecialchars($navBrandHref) ?>" class="nav-brand" aria-label="EssenciaSmile home">
        <img class="nav-brand-logo" src="assets/images/EssenciaSmile-Original-Logo.svg" alt="EssenciaSmile" width="171" height="30">
    </a>
    <a class="topbar-back" href="<?= htmlspecialchars($navBackHref) ?>" aria-label="<?= htmlspecialchars($navBackLabel) ?>">
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> <span class="topbar-back-label"><?= htmlspecialchars($navBackLabel) ?></span>
    </a>
</nav>
