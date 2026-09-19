require('bootstrap/dist/css/bootstrap.min.css');
require('../css/dropzone.css');
require('../css/basic.css');
require('../css/app.css');
require('../css/glass.css');
require('@fortawesome/fontawesome-free/js/all.js');
import $ from 'jquery';
require('bootstrap');
import { setupClipboard } from './clipboard';
import './liquid-glass';
import { enhancePlayers } from './media-player';

setupClipboard();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => { /* not installable here; the site still works */ });
    });
}

$(document).ready(() => {
    enhancePlayers(document);

    // Phone tab bar scrolls sideways: start with the active tab in view.
    const activeTab = document.querySelector('.profile-sidebar .list-group a.active');
    if (activeTab && window.matchMedia('(max-width: 767.98px)').matches) {
        activeTab.scrollIntoView({ block: 'nearest', inline: 'center' });
    }

    // Slide-in drawers: the site menu (left) and, on the gallery, the calendar (right).
    const drawers = [
        { bodyClass: 'sidebar-open', panel: '#sidebar', show: '#sidebarShowBtn', hide: '#sidebarCloseBtn, #sidebarBackdrop' },
        { bodyClass: 'calendar-open', panel: '#calendarPanel', show: '#calendarShowBtn, [data-open-months]', hide: '#calendarCloseBtn, #calendarBackdrop' },
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

    // Remote Upload page: replace the remote upload token in place.
    $('body').on('click', 'button[data-refresh-token]', (e) => {
        const button = $(e.currentTarget);
        if (!window.confirm('Generate a new API token? Remote uploaders using the current token stop working immediately.')) {
            return;
        }
        const result = $('[data-refresh-token-result]');
        button.prop('disabled', true);
        $.ajax({ type: 'POST', url: button.data('refreshUrl') })
            .done((data) => {
                $('#uploadResult').val(data.token);
                result.text('New token generated. Update your uploader configuration.').removeClass('text-danger').prop('hidden', false);
            })
            .fail((xhr) => {
                const message = xhr.responseJSON && xhr.responseJSON.message;
                result.text(message || 'Unable to generate a new token').addClass('text-danger').prop('hidden', false);
            })
            .always(() => button.prop('disabled', false));
    });

    // File view page: owner/admin delete toggle; the page reloads so the stage reflects the new state.
    $('body').on('click', 'button[data-view-delete]', (e) => {
        const button = $(e.currentTarget);
        const action = button.data('action');
        if (action === 'del' && !window.confirm('Mark this file for deletion? It stops being served immediately.')) {
            return;
        }
        button.prop('disabled', true);
        $.ajax({ type: 'POST', url: '/endpoint/setdeletestatus/', data: { id: button.data('viewDelete'), action } })
            .done((data) => {
                if (data.status !== 'ok') {
                    $('[data-view-result]').text('Unable to change file status').addClass('text-danger').prop('hidden', false);
                    return;
                }
                window.location.reload();
            })
            .fail(() => $('[data-view-result]').text('Unable to change file status').addClass('text-danger').prop('hidden', false))
            .always(() => button.prop('disabled', false));
    });

    // Remote Upload page: revoke a signed-in device.
    $('body').on('click', 'button[data-revoke-device]', (e) => {
        const button = $(e.currentTarget);
        const row = button.closest('[data-deviceid]');
        const name = row.find('.admin-title').text().trim();
        if (!window.confirm(`Revoke "${name}"? The app on that device is signed out immediately.`)) {
            return;
        }
        button.prop('disabled', true);
        $.ajax({ type: 'POST', url: button.data('revokeUrl'), data: { id: button.data('revokeDevice') } })
            .done(() => {
                const list = row.closest('[data-devices-list]');
                row.slideUp(200, () => {
                    row.remove();
                    const counter = $('.admin-list-head .admin-chip').first();
                    counter.text(Math.max(parseInt(counter.text(), 10) - 1, 0));
                    if (list.children().length === 0) {
                        list.replaceWith('<p class="admin-sub small mb-4" data-devices-empty>No devices signed in.</p>');
                    }
                });
            })
            .fail(() => { button.prop('disabled', false); window.alert('Unable to revoke this device'); });
    });

})