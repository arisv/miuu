import { Modal } from 'bootstrap';
import { buildPlayer } from './media-player';

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
            // clipboard.js (v1) copies through a textarea appended to <body>; the modal's focus trap
            // steals focus from it and the copy silently fails, so the modal copies on its own.
            $(modalEl).on('click', '[data-preview-copy]', function (e) {
                var button = $(e.currentTarget);
                var text = button.data('copyText') || '';
                var label = button.find('span');
                var feedback = function (ok) {
                    label.text(ok ? 'Copied!' : 'Copy failed');
                    setTimeout(function () { label.text('Copy link'); }, 1500);
                };
                var fallback = function () {
                    var area = $('<textarea readonly aria-hidden="true">').css({position: 'fixed', opacity: 0, left: 0, top: 0}).val(text);
                    $(modalEl).find('.modal-content').append(area);
                    area[0].focus();
                    area[0].select();
                    var ok = false;
                    try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
                    area.remove();
                    button.trigger('focus');
                    feedback(ok);
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(function () { feedback(true); }, fallback);
                } else {
                    fallback();
                }
            });
            $(modalEl).on('hidden.bs.modal', function () {
                $(modalEl).find('.preview-body').empty();
                if (this.cursor) {
                    this.cursor.trigger('focus');
                }
            }.bind(this));
            $(document).on('keydown', this.onPreviewKey.bind(this));
            // Safari has been seen ignoring Escape via the bubbling handlers; catch it early on the window,
            // on both key phases, and retry the hide if the modal is still up after the transition.
            var self = this;
            var isEscape = function (e) { return e.key === 'Escape' || e.key === 'Esc' || e.keyCode === 27; };
            ['keydown', 'keyup'].forEach(function (type) {
                window.addEventListener(type, function (e) {
                    if (!isEscape(e) || !self.isPreviewOpen()) {
                        return;
                    }
                    e.preventDefault();
                    self.closePreview();
                }, true);
            });
        },
        closePreview: function () {
            var self = this;
            var modalEl = document.getElementById('previewModal');
            var instance = Modal.getInstance(modalEl) || this.previewModal;
            instance.hide();
            setTimeout(function () {
                if (modalEl.classList.contains('show')) {
                    instance.hide();
                }
            }, 400);
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
            var last = tiles.length - 1;
            var target = index + delta;
            if (delta > 0 && target > last && this.isPreviewOpen()) {
                // Past the loaded set: fetch the next page and land on the target tile (or the new last one).
                var self = this;
                var request = this.loadNextPage();
                var pending = request || this.pendingLoad;
                if (pending) {
                    this.pendingLoad = pending;
                    pending.done(function () {
                        var refreshed = self.tiles();
                        if (refreshed.length > last + 1) {
                            self.setCursor(refreshed.eq(Math.min(target, refreshed.length - 1)));
                        }
                    });
                    return;
                }
            }
            var next = Math.min(Math.max(index + delta, 0), last);
            this.setCursor(tiles.eq(next));
            if (next === last && this.isPreviewOpen()) {
                this.pendingLoad = this.loadNextPage() || this.pendingLoad;
            }
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
                case 'Escape':
                    if (open) { this.closePreview(); } else { handled = false; }
                    break;
                case 'ArrowRight': this.moveCursor(1); break;
                case 'ArrowLeft': this.moveCursor(-1); break;
                case 'ArrowDown': this.moveCursor(this.columnsPerRow()); break;
                case 'ArrowUp': this.moveCursor(-this.columnsPerRow()); break;
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
        buildPlayer: function (kind, url, options) {
            return buildPlayer(kind, url, options);
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
            modal.find('[data-preview-open]').attr('href', tile.data('previewView'));
            modal.find('[data-preview-copy]').data('copyText', new URL(url, window.location.href).href);
            modal.find('[data-preview-nav="-1"]').prop('disabled', tiles.index(tile) === 0);
            modal.find('[data-preview-nav="1"]').prop('disabled', tiles.index(tile) === tiles.length - 1 && !this.hasMorePages());
            var placeholder = function (icon, text) {
                return $('<div class="preview-placeholder">').append($('<i class="fa-solid" aria-hidden="true">').addClass(icon)).append($('<div>').text(text));
            };
            var thumb = tile.data('previewThumb');
            if (kind === 'image') {
                // Thumbnail first so the modal has its shape at once; the full image swaps in and may resize it.
                var full = $('<img>').attr({src: url, alt: name});
                if (thumb) {
                    var placeholder = $('<img class="preview-thumb">').attr({src: thumb, alt: ''});
                    body.append(placeholder);
                    full.on('load', function () { if (placeholder.parent().length) { placeholder.replaceWith(full); } });
                    full.on('error', function () { placeholder.removeClass('preview-thumb'); });
                } else {
                    body.append(full);
                }
            } else if (kind === 'video' || kind === 'audio') {
                var player = this.buildPlayer(kind, url, {poster: kind === 'video' ? thumb : null});
                body.append(player);
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
        hasMorePages: function () {
            var button = $('.gallery-pager button[data-pagination-next]')[0];
            return !!button && !button.disabled;
        },
        fetchNextPage: function () {
            this.loadNextPage();
        },
        // Returns the request, or null when nothing is left to load or a load is already running.
        loadNextPage: function () {
            if (this.fetchLock) {
                return null;
            }
            var button = $('.gallery-pager button[data-pagination-next]')[0];
            if (!button || button.disabled) {
                return null;
            }
            var action = $(button).data('paginationNext');
            $('#fetch-in-progress').show();
            this.fetchLock = true;
            var self = this;
            return this.getData(action)
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