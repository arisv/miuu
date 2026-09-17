/*
 * Liquid Glass runtime helpers: illumination only.
 *
 * Tracks the pointer over glass surfaces and buttons so the CSS glow can start under the cursor,
 * and marks presses so the material can compress and spring back. No filters, no snapshots.
 * Inert unless <body class="glass-ui"> is present.
 */

const SURFACES = '#userheader, #navpanel, .admin-menu, .profile-sidebar .list-group, #sidebar';

function setupIllumination() {
    const glassy = `${SURFACES}, .btn, .itembox-controls`;
    // A wrapper with data-lg-proxy forwards its feedback to the matching child (e.g. the pager button).
    const resolve = (target) => {
        if (!target.closest) return null;
        const proxy = target.closest('[data-lg-proxy]');
        if (proxy) {
            const child = proxy.querySelector(proxy.dataset.lgProxy);
            if (child) return child;
        }
        return target.closest(glassy);
    };
    document.addEventListener('pointermove', (e) => {
        const el = resolve(e.target);
        if (!el) return;
        const r = el.getBoundingClientRect();
        el.style.setProperty('--lg-x', `${e.clientX - r.left}px`);
        el.style.setProperty('--lg-y', `${e.clientY - r.top}px`);
    }, { passive: true });
    document.addEventListener('pointerdown', (e) => {
        const el = resolve(e.target);
        if (el) el.classList.add('lg-pressed');
    }, { passive: true });
    const release = () => document.querySelectorAll('.lg-pressed').forEach((el) => el.classList.remove('lg-pressed'));
    document.addEventListener('pointerup', release, { passive: true });
    document.addEventListener('pointercancel', release, { passive: true });
}

function init() {
    if (!document.body.classList.contains('glass-ui')) return;
    setupIllumination();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
