import { Modal } from 'bootstrap';

$(document).ready(function () {
    var UserGalleryControls = {
        fetchLock: false,
        calendarRangeStart: null,
        calendarRangeEnd: null,
        calendarPointer: 'start',
        initialize: function () {
            $('body').on('click', 'button[data-deleteid]', this.manageDeletion.bind(this));
            // Bound on the wrapper, which is exactly the button's footprint plus padding, so a click
            // still lands if the button shifts under the pointer while the page re-flows.
            $('.gallery-pager').on('click', this.fetchNextPage.bind(this));
            $('[data-date]').on('click', this.calendarHighlight.bind(this));
            $('#order-form').on('submit', function (e) {
                var el = e.currentTarget;
                $(el).find("input[name='calendar-start']").val(this.calendarRangeStart);
                $(el).find("input[name='calendar-end']").val(this.calendarRangeEnd);
                $(el).find(":input").filter(function () {
                    return !this.value;
                }).attr("disabled", "disabled");
                return true;
            }.bind(this));
            this.applyExistingFilter(__filter);
            this.initializeSelection();
            this.initializePreview();
        },
        // Preview: a keyboard cursor over the tiles (arrows), Space toggles the modal, tile button opens it.
        initializePreview: function () {
            var modalEl = document.getElementById('previewModal');
            if (!modalEl) {
                return;
            }
            this.previewModal = new Modal(modalEl);
            this.cursor = null;
            $('body').on('click', '[data-preview]', this.onPreviewButton.bind(this));
            $('body').on('focusin', '.itembox', function (e) {
                this.setCursor($(e.currentTarget), false);
            }.bind(this));
            $(modalEl).on('click', '[data-preview-nav]', function (e) {
                this.moveCursor(parseInt($(e.currentTarget).data('previewNav'), 10));
            }.bind(this));
            $(modalEl).on('click', '[data-preview-copy]', function (e) {
                // app.js wires clipboard.js to .clipbutton; this only gives feedback.
                var label = $(e.currentTarget).find('span');
                label.text('Copied!');
                setTimeout(function () { label.text('Copy link'); }, 1500);
            });
            $(modalEl).on('hidden.bs.modal', function () {
                $(modalEl).find('.preview-body').empty();
                if (this.cursor) {
                    this.cursor.trigger('focus');
                }
            }.bind(this));
            $(document).on('keydown', this.onPreviewKey.bind(this));
        },
        tiles: function () {
            return $('#file-container .itembox');
        },
        setCursor: function (tile, scroll) {
            $('.itembox.is-cursor').removeClass('is-cursor');
            this.cursor = tile && tile.length ? tile : null;
            if (this.cursor) {
                this.cursor.addClass('is-cursor');
                if (scroll !== false) {
                    this.cursor[0].scrollIntoView({block: 'nearest', inline: 'nearest'});
                    if (!this.isPreviewOpen()) {
                        this.cursor.trigger('focus');
                    }
                }
                if (this.isPreviewOpen()) {
                    this.renderPreview();
                }
            }
        },
        columnsPerRow: function () {
            var tiles = this.tiles();
            if (tiles.length < 2) {
                return 1;
            }
            var top = tiles.first().closest('.col')[0].offsetTop;
            var count = 0;
            tiles.each(function () {
                if ($(this).closest('.col')[0].offsetTop === top) {
                    count++;
                } else {
                    return false;
                }
            });
            return Math.max(count, 1);
        },
        moveCursor: function (delta) {
            var tiles = this.tiles();
            if (!tiles.length) {
                return;
            }
            var index = this.cursor ? tiles.index(this.cursor) : -1;
            if (index < 0) {
                index = delta > 0 ? -1 : 0;
            }
            var next = Math.min(Math.max(index + delta, 0), tiles.length - 1);
            this.setCursor(tiles.eq(next));
        },
        isPreviewOpen: function () {
            return $('#previewModal').hasClass('show');
        },
        onPreviewKey: function (e) {
            if ($(e.target).is('input, select, textarea') && (!this.isPreviewOpen() || $(e.target).is('.player-seek'))) {
                return;
            }
            if (document.body.classList.contains('calendar-open') || document.body.classList.contains('sidebar-open')) {
                return;
            }
            var open = this.isPreviewOpen();
            var handled = true;
            switch (e.key) {
                case 'ArrowRight': this.moveCursor(1); break;
                case 'ArrowLeft': this.moveCursor(-1); break;
                case 'ArrowDown': this.moveCursor(open ? 1 : this.columnsPerRow()); break;
                case 'ArrowUp': this.moveCursor(open ? -1 : -this.columnsPerRow()); break;
                case ' ':
                case 'Enter':
                    if ($(e.target).is('button, a') && !open) {
                        handled = false;
                        break;
                    }
                    if (open && this.currentMedia()) {
                        var media = this.currentMedia();
                        media.paused ? media.play() : media.pause();
                    } else if (open) {
                        this.previewModal.hide();
                    } else if (this.cursor) {
                        this.openPreview(this.cursor);
                    } else {
                        handled = false;
                    }
                    break;
                default:
                    handled = false;
            }
            if (handled) {
                e.preventDefault();
            }
        },
        // Custom media controls in the glass style; native controls cannot be themed.
        buildPlayer: function (kind, url) {
            var media = $(kind === 'video' ? '<video playsinline preload="metadata">' : '<audio preload="metadata">').attr('src', url);
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
        },
        currentMedia: function () {
            return $('#previewModal .preview-player video, #previewModal .preview-player audio')[0] || null;
        },
        onPreviewButton: function (e) {
            e.preventDefault();
            e.stopPropagation();
            this.openPreview($(e.currentTarget).closest('.itembox'));
        },
        openPreview: function (tile) {
            this.setCursor(tile, false);
            this.renderPreview();
            this.previewModal.show();
        },
        renderPreview: function () {
            var tile = this.cursor;
            if (!tile) {
                return;
            }
            var modal = $('#previewModal');
            var body = modal.find('.preview-body').empty();
            var kind = tile.data('previewKind');
            var url = tile.data('previewUrl');
            var name = tile.data('previewName');
            var tiles = this.tiles();
            modal.find('#previewTitle').text(name).attr('title', name);
            modal.find('.preview-counter').text((tiles.index(tile) + 1) + ' / ' + tiles.length);
            modal.find('.preview-meta').text(tile.data('previewDate'));
            modal.find('[data-preview-download]').attr('href', url);
            modal.find('[data-preview-copy]').attr('data-clipboard-text', new URL(url, window.location.href).href);
            modal.find('[data-preview-nav="-1"]').prop('disabled', tiles.index(tile) === 0);
            modal.find('[data-preview-nav="1"]').prop('disabled', tiles.index(tile) === tiles.length - 1);
            var placeholder = function (icon, text) {
                return $('<div class="preview-placeholder">').append($('<i class="fa-solid" aria-hidden="true">').addClass(icon)).append($('<div>').text(text));
            };
            if (kind === 'image') {
                body.append($('<img>').attr({src: url, alt: name}));
            } else if (kind === 'video' || kind === 'audio') {
                body.append(this.buildPlayer(kind, url));
            } else if (kind === 'hidden') {
                body.append(placeholder('fa-trash', 'This file is marked for deletion, so it cannot be previewed.'));
            } else {
                body.append(placeholder('fa-file', 'No preview for this file type. Use Download to open it.'));
            }
        },
        // Touch layouts: hover controls are unreachable, so tiles are selected and acted on from a fixed bar.
        initializeSelection: function () {
            this.selectionMedia = window.matchMedia('(max-width: 767.98px), (hover: none)');
            this.selectionMedia.addEventListener('change', this.updateSelectionBar.bind(this));
            $('body').on('change', '.itembox-check', this.onSelectionChange.bind(this));
            $('body').on('click', '.itembox', this.onTileTap.bind(this));
            $('#selectionBar').on('click', '[data-selection-action]', this.onSelectionAction.bind(this));
        },
        onTileTap: function (e) {
            if (!this.selectionMedia.matches) {
                return;
            }
            if ($(e.target).closest('.itembox-controls, .itembox-select').length) {
                return;
            }
            var box = $(e.currentTarget).find('.itembox-check');
            box.prop('checked', !box.prop('checked')).trigger('change');
        },
        onSelectionChange: function (e) {
            $(e.target).closest('.itembox').toggleClass('is-selected', e.target.checked);
            this.updateSelectionBar();
        },
        selectedTiles: function () {
            return $('.itembox-check:checked').closest('.itembox');
        },
        visibleDeleteButtons: function (tiles, action) {
            return tiles.find('button[data-deleteaction="' + action + '"]').filter(function () {
                return this.style.display !== 'none';
            });
        },
        updateSelectionBar: function () {
            var tiles = this.selectedTiles();
            var count = tiles.length;
            $('body').toggleClass('has-selection', count > 0 && this.selectionMedia.matches);
            var bar = $('#selectionBar');
            bar.find('.selection-count').text(count + (count === 1 ? ' file' : ' files'));
            bar.find('[data-selection-action="preview"]').prop('disabled', count !== 1);
            bar.find('[data-selection-action="del"]').prop('disabled', this.visibleDeleteButtons(tiles, 'del').length === 0);
            bar.find('[data-selection-action="undo"]').prop('disabled', this.visibleDeleteButtons(tiles, 'undo').length === 0);
        },
        clearSelection: function () {
            $('.itembox-check:checked').prop('checked', false);
            $('.itembox.is-selected').removeClass('is-selected');
            this.updateSelectionBar();
        },
        onSelectionAction: function (e) {
            var action = $(e.currentTarget).data('selectionAction');
            var tiles = this.selectedTiles();
            if (action === 'clear') {
                this.clearSelection();
            } else if (action === 'preview') {
                if (tiles.length === 1) {
                    this.openPreview(tiles.first());
                }
            } else if (action === 'download') {
                var urls = tiles.find('.itembox-controls a').map(function () {
                    return this.href;
                }).get();
                if (urls.length === 1) {
                    window.location.href = urls[0];
                } else {
                    urls.forEach(function (url) {
                        var link = document.createElement('a');
                        link.href = url;
                        link.download = '';
                        document.body.appendChild(link);
                        link.click();
                        link.remove();
                    });
                }
            } else if (action === 'del' || action === 'undo') {
                // Reuses the per-tile buttons so the endpoint call and state toggle stay in one place.
                this.visibleDeleteButtons(tiles, action).each(function () {
                    $(this).trigger('click');
                });
            }
        },
        calendarHighlight: function (e) {
            var el = e.currentTarget;
            var date = $(el).data('date');

            if (this.calendarPointer === 'start') {
                this.calendarRangeStart = date;
                this.calendarRangeEnd = null;
                this.calendarPointer = 'end';
                this.markRangeStart(el);
            } else if (this.calendarPointer === 'end') {
                this.calendarRangeEnd = date;
                this.calendarPointer = 'start';
                this.clearRangeStart();
            }
            this.repaintCalendar(this.calendarRangeStart, this.calendarRangeEnd);
        },
        markRangeStart: function (el) {
            this.clearRangeStart();
            $(el).addClass('calendar-range-start')
                .find('.calendar-hint').prop('hidden', false);
        },
        clearRangeStart: function () {
            $('[data-date]').removeClass('calendar-range-start')
                .find('.calendar-hint').prop('hidden', true);
        },
        repaintCalendar: function (rangeStart, rangeEnd) {
            var months = $('[data-date]');
            $(months).removeClass('calendar-highlight');
            var firstDate = Date.parse(rangeStart);
            var secondDate = Date.parse(rangeEnd);

            if (secondDate > firstDate) {
                var temp = firstDate;
                firstDate = secondDate;
                secondDate = temp;
            }
            $(months).each(function (i, o) {
                var thisDate = Date.parse($(o).data('date'));
                if (thisDate <= firstDate && thisDate >= secondDate) {
                    $(o).addClass('calendar-highlight');
                }
            }.bind(this));
        },
        applyExistingFilter: function (filter) {
            if (filter['calendar-start'] && filter['calendar-start']) {
                this.calendarRangeStart = filter['calendar-start'];
                this.calendarRangeEnd = filter['calendar-end'];
                this.repaintCalendar(this.calendarRangeStart, this.calendarRangeEnd);
            }
            var form = $('#order-form');
            if (filter['order-size']) {
                $(form).find('select[name="order-size"]').val(filter['order-size']);
            }
            if (filter['order-date']) {
                $(form).find('select[name="order-date"]').val(filter['order-date']);
            }
            if (filter['page-size']) {
                $(form).find('select[name="page-size"]').val(filter['page-size']);
            }
        },
        manageDeletion: function (e) {
            var pressed = e.currentTarget;
            var itemId = $(pressed).data('deleteid');
            var action = $(pressed).data('deleteaction');
            var newAction = 'del';
            if (action === 'del') {
                newAction = 'undo';
            }
            var newButton = $('button[data-deleteid="' + itemId + '"][data-deleteaction="' + newAction + '"]');
            var self = this;
            $(pressed).prop('disabled', true);
            this.postData('/endpoint/setdeletestatus/', {
                    'id': itemId,
                    'action': action
                })
                .done(function (data, status, xhr) {
                    if (data.status === 'ok') {
                        $(pressed).prop('disabled', false);
                        $(pressed).hide();
                        $(newButton).show();
                        self.updateSelectionBar();
                    }
                });
        },
        fetchNextPage: function (e) {
            if (this.fetchLock) {
                console.log("Fetch in progress");
                return;
            }
            var button = $(e.currentTarget).find('button[data-pagination-next]')[0];
            if (!button || button.disabled) {
                return;
            }
            var action = $(button).data('paginationNext');
            $('#fetch-in-progress').show();
            this.fetchLock = true;
            var self = this;
            this.getData(action)
                .done(function (data) {
                    var container = $('#file-container');
                    $.each(data.rendered, function (i, o) {
                        container.append($(o));
                    });
                    $(button).data('paginationNext', data.nextPageRequest);
                    if (!data.hasNextPage) {
                        $(button).prop('disabled', true);
                        $(button).text("No more files!");
                    }
                })
                .fail(function (data) {

                })
                .always(function () {
                    self.fetchLock = false;
                    $('#fetch-in-progress').hide();
                });
        },
        postData: function (url, data) {
            return $.ajax({
                type: 'POST',
                url: url,
                data: data
            });
        },
        getData: function (url) {
            return $.ajax({
                type: 'GET',
                url: url
            });
        },
    };

    UserGalleryControls.initialize();
});