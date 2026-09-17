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
    var controls = $(
        '<div class="player-controls">' +
            '<button type="button" class="player-btn" data-player="toggle" title="Play/pause (Space)"><i class="fa-solid fa-play" aria-hidden="true"></i></button>' +
            '<span class="player-time" data-player="current">0:00</span>' +
            '<input type="range" class="player-seek" min="0" max="1000" step="1" value="0" aria-label="Seek">' +
            '<span class="player-time" data-player="duration">0:00</span>' +
            '<button type="button" class="player-btn" data-player="mute" title="Mute"><i class="fa-solid fa-volume-high" aria-hidden="true"></i></button>' +
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
    media.on('volumechange', function () { setIcon('mute', el.muted || el.volume === 0 ? 'fa-volume-xmark' : 'fa-volume-high'); });
    controls.on('click', '[data-player="toggle"]', function () { el.paused ? el.play() : el.pause(); });
    if (kind === 'video') { media.on('click', function () { el.paused ? el.play() : el.pause(); }); }
    controls.on('click', '[data-player="mute"]', function () { el.muted = !el.muted; });
    controls.on('click', '[data-player="fullscreen"]', function () {
        if (document.fullscreenElement) { document.exitFullscreen(); } else if (player[0].requestFullscreen) { player[0].requestFullscreen(); }
    });
    seek.on('pointerdown', function () { seek.data('scrubbing', true); });
    seek.on('input', function () { if (el.duration) { el.currentTime = seek.val() / 1000 * el.duration; } });
    seek.on('pointerup change', function () { seek.data('scrubbing', false); });
    return player;
}

/* Enhances <div data-media-player="video|audio" data-src data-poster> placeholders, replacing their fallback markup. */
export function enhancePlayers(root) {
    $(root || document).find('[data-media-player]').each(function () {
        var host = $(this);
        host.empty().append(buildPlayer(host.data('mediaPlayer'), host.data('src'), { poster: host.data('poster') }));
    });
}
