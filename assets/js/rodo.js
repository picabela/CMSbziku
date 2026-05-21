/* RODO Consent Banner — logika klienta */
(function(){
    'use strict';
    const COOKIE_NAME = 'rodo_consent';
    const banner = document.getElementById('rodo-banner');
    const toggleBtn = document.getElementById('rodo-toggle-btn');
    if (!banner || !toggleBtn) return;

    const lifetimeDays = parseInt(banner.dataset.lifetime || '365', 10);
    const consentModeEnabled = banner.dataset.consentMode === '1';

    function readCookie(name) {
        const parts = document.cookie.split(';');
        for (const p of parts) {
            const [k, v] = p.trim().split('=');
            if (k === name) return decodeURIComponent(v || '');
        }
        return null;
    }
    function writeCookie(name, value, days) {
        const d = new Date();
        d.setTime(d.getTime() + days * 86400000);
        document.cookie = name + '=' + encodeURIComponent(value)
            + ';expires=' + d.toUTCString()
            + ';path=/;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
    }

    // Tab switching
    banner.querySelectorAll('.rodo-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            const tabId = tab.dataset.tab;
            banner.querySelectorAll('.rodo-tab').forEach(t => {
                t.classList.toggle('is-active', t === tab);
                t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
            });
            banner.querySelectorAll('.rodo-panel').forEach(p => {
                const active = p.dataset.panel === tabId;
                p.classList.toggle('is-active', active);
                if (active) p.removeAttribute('hidden'); else p.setAttribute('hidden', '');
            });
        });
    });

    function showBanner() { banner.removeAttribute('hidden'); toggleBtn.setAttribute('hidden', ''); }
    function hideBanner() { banner.setAttribute('hidden', ''); toggleBtn.removeAttribute('hidden'); }

    function applyConsent(consent) {
        if (!consentModeEnabled || typeof gtag === 'undefined') return;
        // Mapuj nasze kategorie na Consent Mode v2 sygnały
        const map = {
            'security_storage': true,  // niezbędne — zawsze granted
            'functionality_storage': !!consent.preferences,
            'personalization_storage': !!consent.preferences,
            'analytics_storage': !!consent.statistics,
            'ad_storage': !!consent.marketing,
            'ad_user_data': !!consent.marketing,
            'ad_personalization': !!consent.marketing,
        };
        const update = {};
        for (const key in map) update[key] = map[key] ? 'granted' : 'denied';
        gtag('consent', 'update', update);

        // Dispatch event dla integracji własnych
        window.dispatchEvent(new CustomEvent('rodo:consent', { detail: consent }));
    }

    function getCheckedCategories() {
        const consent = { _ts: Date.now(), _ver: 1 };
        banner.querySelectorAll('input[type=checkbox][data-category]').forEach(input => {
            consent[input.dataset.category] = input.disabled ? true : input.checked;
        });
        return consent;
    }

    function setAllCategories(value) {
        banner.querySelectorAll('input[type=checkbox][data-category]').forEach(input => {
            if (!input.disabled) input.checked = value;
        });
    }

    function saveAndClose(consent) {
        writeCookie(COOKIE_NAME, JSON.stringify(consent), lifetimeDays);
        applyConsent(consent);
        hideBanner();
    }

    banner.querySelector('[data-action="all"]').addEventListener('click', () => {
        setAllCategories(true);
        saveAndClose(getCheckedCategories());
    });
    banner.querySelector('[data-action="reject"]').addEventListener('click', () => {
        setAllCategories(false);
        saveAndClose(getCheckedCategories());
    });
    banner.querySelector('[data-action="selected"]').addEventListener('click', () => {
        saveAndClose(getCheckedCategories());
    });

    toggleBtn.addEventListener('click', () => {
        // Wczytaj zapisany stan do checkboxów
        const saved = readCookie(COOKIE_NAME);
        if (saved) {
            try {
                const data = JSON.parse(saved);
                banner.querySelectorAll('input[type=checkbox][data-category]').forEach(input => {
                    if (!input.disabled) {
                        input.checked = !!data[input.dataset.category];
                    }
                });
            } catch (e) {}
        }
        showBanner();
    });

    // Init
    const saved = readCookie(COOKIE_NAME);
    if (saved) {
        try {
            const data = JSON.parse(saved);
            applyConsent(data);
            hideBanner();
        } catch (e) {
            showBanner();
        }
    } else {
        showBanner();
    }
})();
