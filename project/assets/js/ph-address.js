/* =============================================================
   EssenciaSmile — Philippine address dropdowns (Province → City → Barangay)
   File: assets/js/ph-address.js
   Used by: branches-records.php (assets/js/branches.js)

   Same data and matching as the Patient Portal's address fields
   (portal-dashboard.php): assets/json/cluster.json, values kept as the
   exact cluster.json names, matched ignoring capitalisation when a saved
   address is shown again. Drives three ordinary <select class="js-custom-select">
   elements, so they look and work like every other admin dropdown
   (custom-select.js rebuilds a dropdown when its options change).

   const addr = PhAddress.attach({ province: sel, city: sel, barangay: sel });
   addr.set('Metro Manila', 'City of Makati', 'Bel-Air')   // → Promise
   addr.get()   // { province, city, barangay } (cluster.json names, '' when not chosen)
   ============================================================= */
(function () {
    'use strict';

    let DATA = null, loading = null;
    function load() {
        if (!loading) {
            loading = fetch('assets/json/cluster.json')
                .then(r => (r.ok ? r.json() : null))
                .then(d => (DATA = d))
                .catch(() => { loading = null; return null; });   // allow a retry
        }
        return loading;
    }

    const key = s => String(s || '').toUpperCase().replace(/\s+/g, ' ').trim();
    // Display label for a cluster.json name (all capitals) — the saved value is
    // tidied on the server with normalize_address_text(), same as the portal
    const KEEP_UPPER = ['NCR', 'BGC', 'CAR', 'ARMM', 'BARMM', 'CALABARZON', 'MIMAROPA', 'SOCCSKSARGEN'];
    const label = s => String(s || '').toLowerCase().replace(/[\p{L}\p{M}'’]+/gu, (w, i) => {
        const up = w.toUpperCase();
        if (KEEP_UPPER.includes(up) || /^(i{1,3}|iv|vi{0,3}|ix|x{1,3})$/i.test(w)) return up;
        if (i > 0 && (w === 'of' || w === 'and')) return w;
        return w.charAt(0).toUpperCase() + w.slice(1);
    });

    function provinces() {
        const out = [];
        Object.entries(DATA || {}).forEach(([rc, r]) =>
            Object.keys(r.province_list || {}).forEach(p => out.push({ region: rc, name: p })));
        return out.sort((a, b) => a.name.localeCompare(b.name));
    }
    function findProvince(name) {
        const k = key(name);
        for (const [rc, r] of Object.entries(DATA || {})) {
            const hit = Object.keys(r.province_list || {}).find(p => key(p) === k);
            if (hit) return { region: rc, name: hit };
        }
        return null;
    }
    const cityMap = (prov) => (prov && DATA?.[prov.region]?.province_list?.[prov.name]?.municipality_list) || {};

    function fill(sel, placeholder, values, current) {
        sel.innerHTML = '';
        const ph = document.createElement('option');
        ph.value = ''; ph.textContent = placeholder;
        sel.appendChild(ph);
        values.forEach(v => {
            const o = document.createElement('option');
            o.value = v; o.textContent = label(v);
            sel.appendChild(o);
        });
        sel.value = current || '';
        if (sel._csRebuild) sel._csRebuild();
    }

    function attach(els) {
        const P = els.province, C = els.city, B = els.barangay;
        let prov = null;

        function fillCities(currentCity) {
            const cities = Object.keys(cityMap(prov)).sort();
            fill(C, prov ? 'Select city / municipality' : 'Select a province first', cities, currentCity);
        }
        function fillBarangays(cityName, currentBrgy) {
            const list = (cityMap(prov)[cityName]?.barangay_list || []).slice().sort();
            fill(B, cityName ? 'Select barangay' : 'Select a city first', list, currentBrgy);
        }

        // Each choice resets the ones below it
        P.addEventListener('change', () => {
            prov = findProvince(P.value);
            fillCities('');
            fillBarangays('', '');
        });
        C.addEventListener('change', () => fillBarangays(C.value, ''));

        function set(province, city, barangay) {
            return load().then(() => {
                fill(P, DATA ? 'Select province' : 'Address list unavailable', provinces().map(p => p.name), '');
                prov = findProvince(province);
                if (prov) P.value = prov.name;
                const cityName = Object.keys(cityMap(prov)).find(c => key(c) === key(city)) || '';
                fillCities(cityName);
                const brgyName = (cityMap(prov)[cityName]?.barangay_list || []).find(b => key(b) === key(barangay)) || '';
                fillBarangays(cityName, brgyName);
                // A saved value that isn't in the list (typed before) — keep it selectable
                [[P, province, prov], [C, city, cityName], [B, barangay, brgyName]].forEach(([sel, saved, found]) => {
                    if (saved && !found) {
                        const o = document.createElement('option');
                        o.value = saved; o.textContent = saved;
                        sel.appendChild(o);
                        sel.value = saved;
                        if (sel._csRebuild) sel._csRebuild();
                    }
                });
                return !!DATA;
            });
        }

        return {
            set,
            get: () => ({ province: P.value, city: C.value, barangay: B.value }),
            ready: load,
        };
    }

    window.PhAddress = { attach, load };
    // Start downloading the list (1.4 MB, cached by the browser) once the page is up
    window.addEventListener('load', () => setTimeout(load, 0));
})();
