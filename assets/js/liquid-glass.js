/*
 * Liquid Glass runtime helpers: illumination only.
 *
 * Tracks the pointer over glass surfaces and buttons so the CSS glow can start under the cursor,
 * and marks presses so the material can compress and spring back. No filters, no snapshots.
 * Inert unless <body class="glass-ui"> is present.
 */

const SURFACES = '#userheader, #loginpanel, #navpanel, .admin-menu, .profile-sidebar .list-group, #sidebar';

function setupIllumination() {
    const glassy = `${SURFACES}, .btn, .itembox-controls`;
    document.addEventListener('pointermove', (e) => {
        const el = e.target.closest && e.target.closest(glassy);
        if (!el) return;
        const r = el.getBoundingClientRect();
        el.style.setProperty('--lg-x', `${e.clientX - r.left}px`);
        el.style.setProperty('--lg-y', `${e.clientY - r.top}px`);
    }, { passive: true });
    document.addEventListener('pointerdown', (e) => {
        const el = e.target.closest && e.target.closest(glassy);
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
