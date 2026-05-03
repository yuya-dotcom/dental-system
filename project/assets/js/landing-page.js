/* assets/js/landingPage.js
   EssenciaSmile Landing Page — external JS
   (no inline scripts per project convention)
*/

// ── Smooth scroll for all in-page anchor links ──────────────────────
document.querySelectorAll('a[href^="#"]').forEach(link => {
    link.addEventListener('click', function (e) {
        const targetId = this.getAttribute('href').slice(1);
        if (!targetId) return;
        const target = document.getElementById(targetId);
        if (!target) return;
        e.preventDefault();

        // Account for sticky navbar height
        const navbarEl = document.querySelector('.es-navbar');
        const navbarH  = navbarEl ? navbarEl.offsetHeight : 0;
        const top = target.getBoundingClientRect().top + window.scrollY - navbarH - 8;

        window.scrollTo({ top, behavior: 'smooth' });

        // Close mobile menu if open
        const mobileMenu = document.getElementById('navMobileMenu');
        const toggler    = document.getElementById('navToggler');
        if (mobileMenu && toggler) {
            mobileMenu.classList.remove('is-open');
            toggler.classList.remove('is-open');
        }
    });
});

// ── Mobile nav toggler ───────────────────────────────────────────────
(function () {
    const toggler    = document.getElementById('navToggler');
    const mobileMenu = document.getElementById('navMobileMenu');
    if (!toggler || !mobileMenu) return;

    toggler.addEventListener('click', () => {
        const open = toggler.classList.toggle('is-open');
        mobileMenu.classList.toggle('is-open', open);
    });
})();