/*
 * Glass-styled media player shared by the gallery previewer and the /v/ page.
 * Native controls cannot be themed, so a small control bar drives the <video>/<audio> element.
 */
import $ from 'jquery';

export function buildPlayer(kind, url, options) {
    var media = $(kind === 'video' ? '<video playsinline preload="metadata">' : '<audio preload="metadata">').attr('src', url);
    if (kind === 'video' && options && options.poster) { media.attr('poster', options.poster); }
    var player = $('<div class="preview-player is-paused">').addClass('is-' + kind);
    if (kind === 'audio') {
        player.append('<div class="player-artwork"><i class="fa-solid fa-music" aria-hidden="true"></i></div>');
    }
    // Volume persists across files and pages (browser local storage); muted is remembered too.
    var stored = readVolume();
    var controls = $(
        '<div class="player-controls">' +
            '<button type="button" class="player-btn" data-player="toggle" title="Play/pause (Space)"><i class="fa-solid fa-play" aria-hidden="true"></i></button>' +
            '<div class="player-volume">' +
                '<button type="button" class="player-btn" data-player="mute" title="Mute"><i class="fa-solid fa-volume-high" aria-hidden="true"></i></button>' +
                '<input type="range" class="player-volume-slider" min="0" max="100" step="1" value="' + Math.round(stored.volume * 100) + '" aria-label="Volume">' +
            '</div>' +
            '<span class="player-time" data-player="current">0:00</span>' +
            '<input type="range" class="player-seek" min="0" max="1000" step="1" value="0" aria-label="Seek">' +
            '<span class="player-time" data-player="duration">0:00</span>' +
            (kind === 'video' ? '<button type="button" class="player-btn" data-player="fullscreen" title="Fullscreen"><i class="fa-solid fa-expand" aria-hidden="true"></i></button>' : '') +
        '</div>'
    );
    player.append(media, controls);
    var el = media[0];
    var seek = controls.find('.player-seek');
    var fmt = function (t) {
        if (!isFinite(t)) { return '0:00'; }
        var m = Math.floor(t / 60), sec = Math.floor(t % 60);
        return m + ':' + (sec < 10 ? '0' : '') + sec;
    };
    var setIcon = function (btn, icon) {
        var i = controls.find('[data-player="' + btn + '"] > i, [data-player="' + btn + '"] > svg');
        i.replaceWith($('<i class="fa-solid" aria-hidden="true">').addClass(icon));
    };
    media.on('loadedmetadata durationchange', function () { controls.find('[data-player="duration"]').text(fmt(el.duration)); });
    media.on('timeupdate', function () {
        controls.find('[data-player="current"]').text(fmt(el.currentTime));
        if (el.duration && !seek.data('scrubbing')) { seek.val(Math.round(el.currentTime / el.duration * 1000)); }
    });
    media.on('play', function () { player.removeClass('is-paused'); setIcon('toggle', 'fa-pause'); });
    media.on('pause ended', function () { player.addClass('is-paused'); setIcon('toggle', 'fa-play'); });
    var volumeSlider = controls.find('.player-volume-slider');
    el.volume = stored.volume;
    el.muted = stored.muted;
    var volumeIcon = function () {
        if (el.muted || el.volume === 0) { return 'fa-volume-xmark'; }
        return el.volume < 0.5 ? 'fa-volume-low' : 'fa-volume-high';
    };
    media.on('volumechange', function () {
        setIcon('mute', volumeIcon());
        volumeSlider.val(el.muted ? 0 : Math.round(el.volume * 100));
        writeVolume(el.volume, el.muted);
    });
    volumeSlider.on('input', function () {
        el.volume = volumeSlider.val() / 100;
        el.muted = el.volume === 0;
    });
    media.on('dblclick', function () {
        if (kind === 'video') { controls.find('[data-player="fullscreen"]').trigger('click'); }
    });
    $(document).on('fullscreenchange', function () {
        setIcon('fullscreen', document.fullscreenElement === player[0] ? 'fa-compress' : 'fa-expand');
    });
    controls.on('click', '[data-player="toggle"]', function () { el.paused ? el.play() : el.pause(); });
    if (kind === 'video') { media.on('click', function () { el.paused ? el.play() : el.pause(); }); }
    controls.on('click', '[data-player="mute"]', function () {
        if (el.muted || el.volume === 0) { el.muted = false; if (el.volume === 0) { el.volume = 0.5; } } else { el.muted = true; }
    });
    controls.on('click', '[data-player="fullscreen"]', function () {
        if (document.fullscreenElement) { document.exitFullscreen(); } else if (player[0].requestFullscreen) { player[0].requestFullscreen(); }
    });
    seek.on('pointerdown', function () { seek.data('scrubbing', true); });
    seek.on('input', function () { if (el.duration) { el.currentTime = seek.val() / 1000 * el.duration; } });
    seek.on('pointerup change', function () { seek.data('scrubbing', false); });
    return player;
}

var VOLUME_KEY = 'miu.player.volume';

function readVolume() {
    try {
        var raw = window.localStorage.getItem(VOLUME_KEY);
        if (raw) {
            var parsed = JSON.parse(raw);
            var v = Number(parsed.volume);
            return { volume: isFinite(v) ? Math.min(1, Math.max(0, v)) : 1, muted: !!parsed.muted };
        }
    } catch (e) { /* storage unavailable: fall through to defaults */ }
    return { volume: 1, muted: false };
}

function writeVolume(volume, muted) {
    try { window.localStorage.setItem(VOLUME_KEY, JSON.stringify({ volume: volume, muted: muted })); } catch (e) { /* ignore */ }
}

/* Enhances <div data-media-player="video|audio" data-src data-poster> placeholders, replacing their fallback markup. */
export function enhancePlayers(root) {
    $(root || document).find('[data-media-player]').each(function () {
        var host = $(this);
        host.empty().append(buildPlayer(host.data('mediaPlayer'), host.data('src'), { poster: host.data('poster') }));
    });
}
