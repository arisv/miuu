require('bootstrap/dist/css/bootstrap.min.css');
require('../css/dropzone.css');
require('../css/basic.css');
require('../css/app.css');
require('../css/glass.css');
require('@fortawesome/fontawesome-free/js/all.js');
const $ = require('jquery');
require('bootstrap');
import * as Clipboard from './clipboard.min';
import './liquid-glass';

new Clipboard('.clipbutton');

$(document).ready(() => {
    // Slide-in drawers: the site menu (left) and, on the gallery, the calendar (right).
    const drawers = [
        { bodyClass: 'sidebar-open', panel: '#sidebar', show: '#sidebarShowBtn', hide: '#sidebarCloseBtn, #sidebarBackdrop' },
        { bodyClass: 'calendar-open', panel: '#calendarPanel', show: '#calendarShowBtn', hide: '#calendarCloseBtn, #calendarBackdrop' },
    ];
    const setOpen = (d, open) => {
        document.body.classList.toggle(d.bodyClass, open);
        $(d.panel).attr('aria-hidden', open ? 'false' : 'true');
        $(d.show).attr('aria-expanded', open ? 'true' : 'false');
    };
    drawers.forEach((d) => {
        $(d.show).on('click', () => setOpen(d, true));
        $(d.hide).on('click', () => setOpen(d, false));
    });
    $(document).on('keydown', (e) => { if (e.key === 'Escape') drawers.forEach((d) => setOpen(d, false)); });

    // Gallery: on phones the order toolbar lives inside the calendar drawer, on wider screens above the tiles.
    const navpanel = document.getElementById('navpanel');
    const slot = document.getElementById('calendarToolbarSlot');
    if (navpanel && slot) {
        const home = document.createComment('navpanel-home');
        navpanel.before(home);
        const phone = window.matchMedia('(max-width: 767.98px)');
        const dock = () => { if (phone.matches) slot.append(navpanel); else home.after(navpanel); };
        dock();
        phone.addEventListener('change', dock);
    }
})