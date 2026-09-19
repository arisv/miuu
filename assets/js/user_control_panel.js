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
            // Toolbar: every sort/group/order choice, the calendar range and "clear" reload the
            // page with the new query string (the cursor never survives a change of ordering).
            $('body').on('click', '[data-order-field]', this.onOrderChoice.bind(this));
            $('body').on('click', '[data-clear-range]', function () {
                this.navigate({'calendar-start': null, 'calendar-end': null});
            }.bind(this));
            $('#calendarRangeApply').on('click', this.applyCalendarRange.bind(this));
            $('#galleryRefreshBtn').on('click', function () { window.location.reload(); });
            $('#gallerySelectBtn').on('click', this.toggleSelectionMode.bind(this));
            $('[data-toggle-months]').on('click', this.toggleMonths.bind(this));
            this.restoreMonths();
            this.applyExistingFilter(__filter);
            this.initializeSelection();
            this.initializePreview();
            this.initializeZoom();
            this.initializeInfiniteScroll();
        },
        // ---- Zoom: pinch (touch) or ctrl+wheel (trackpad) scales the tile size. Same rules as the
        // app: min 36 px, at most 10 columns on phones / 20 on desktop, live relayout to whole
        // columns plus a transform for the fractional remainder that eases away on release.
        tileSize: 200,
        minTileSize: 36,
        maxTileSize: 360,
        initializeZoom: function () {
            var container = document.getElementById('file-container');
            if (!container) {
                return;
            }
            this.grid = container;
            var stored = null;
            try { stored = parseFloat(window.localStorage.getItem('miu.gallery.tileSize')); } catch (err) { /* private mode */ }
            if (stored && stored >= this.minTileSize && stored <= this.maxTileSize) {
                this.tileSize = stored;
            }
            this.applyTileSize(this.tileSize, false);
            window.addEventListener('resize', function () { this.applyTileSize(this.tileSize, false); }.bind(this));

            var area = container.closest('.gallery-files') || container;
            var pinch = null;
            var distance = function (t) { return Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY); };
            area.addEventListener('touchstart', function (e) {
                if (e.touches.length === 2) {
                    var rect = container.getBoundingClientRect();
                    var focalY = ((e.touches[0].clientY + e.touches[1].clientY) / 2 - rect.top) / Math.max(rect.height, 1);
                    container.style.setProperty('--pinch-origin-y', (Math.min(Math.max(focalY, 0), 1) * 100) + '%');
                    pinch = { start: distance(e.touches), size: this.tileSize, live: this.tileSize };
                    container.classList.remove('is-snapping');
                    container.classList.add('is-pinching');
                }
            }.bind(this), { passive: true });
            area.addEventListener('touchmove', function (e) {
                if (!pinch || e.touches.length !== 2) {
                    return;
                }
                e.preventDefault();
                pinch.live = this.clampTile(pinch.size * distance(e.touches) / pinch.start);
                this.applyTileSize(pinch.live, true);
            }.bind(this), { passive: false });
            var end = function () {
                if (!pinch) {
                    return;
                }
                this.commitTileSize(pinch.live);
                pinch = null;
            }.bind(this);
            area.addEventListener('touchend', end);
            area.addEventListener('touchcancel', end);

            // Trackpad pinch arrives as a wheel event with ctrlKey; zoom in steps and settle after a pause.
            var wheelTimer = null;
            area.addEventListener('wheel', function (e) {
                if (!e.ctrlKey) {
                    return;
                }
                e.preventDefault();
                var live = this.clampTile((this.liveTileSize || this.tileSize) * (1 - e.deltaY * 0.01));
                this.liveTileSize = live;
                container.classList.remove('is-snapping');
                container.classList.add('is-pinching');
                this.applyTileSize(live, true);
                window.clearTimeout(wheelTimer);
                wheelTimer = window.setTimeout(function () {
                    this.commitTileSize(this.liveTileSize);
                    this.liveTileSize = null;
                }.bind(this), 160);
            }.bind(this), { passive: false });
        },
        clampTile: function (size) {
            return Math.min(this.maxTileSize, Math.max(this.minTileSize, size));
        },
        columnsFor: function (size) {
            var width = this.grid.clientWidth || window.innerWidth;
            var max = window.innerWidth < 768 ? 10 : 20;
            return Math.min(max, Math.max(2, Math.floor(width / size)));
        },
        // Lays the grid out for a tile size; `live` keeps the fractional remainder as a transform.
        applyTileSize: function (size, live) {
            var cols = this.columnsFor(size);
            var actual = (this.grid.clientWidth || window.innerWidth) / cols;
            this.grid.style.gridTemplateColumns = 'repeat(' + cols + ', minmax(0, 1fr))';
            this.grid.style.transform = live ? 'scale(' + (size / actual) + ')' : '';
            document.body.classList.toggle('tiles-zoomed', actual < 200);
            document.body.classList.toggle('tiles-compact', actual < 150);
            document.body.classList.toggle('tiles-tiny', actual < 72);
            this.tileWidth = actual;
            this.columns = cols;
        },
        commitTileSize: function (size) {
            this.tileSize = this.clampTile(size);
            try { window.localStorage.setItem('miu.gallery.tileSize', String(this.tileSize)); } catch (err) { /* private mode */ }
            var grid = this.grid;
            grid.classList.remove('is-pinching');
            grid.classList.add('is-snapping');
            grid.style.transform = 'scale(1)';
            var done = function () {
                grid.classList.remove('is-snapping');
                grid.style.transform = '';
                grid.removeEventListener('transitionend', done);
            };
            grid.addEventListener('transitionend', done);
            window.setTimeout(done, 250);
            // More tiles fit now: fill the viewport if the end came into view.
            this.autoLoad();
        },
        // ---- Infinite scroll: the pager is watched; a page that does not fill the viewport
        // fetches the next one, and every fetch asks for a page sized to the viewport and zoom.
        initializeInfiniteScroll: function () {
            var pager = document.querySelector('.gallery-pager');
            if (!pager || !('IntersectionObserver' in window)) {
                return;
            }
            this.pager = pager;
            new IntersectionObserver(function (entries) {
                if (entries.some(function (en) { return en.isIntersecting; })) {
                    this.autoLoad();
                }
            }.bind(this), { rootMargin: '600px 0px' }).observe(pager);
        },
        pagerNear: function () {
            if (!this.pager) {
                return false;
            }
            return this.pager.getBoundingClientRect().top < window.innerHeight + 600;
        },
        pageSizeFor: function () {
            var cols = this.columns || 4;
            var tileHeight = document.body.classList.contains('tiles-zoomed') ? (this.tileWidth || 200) : 240;
            var needed = cols * (Math.ceil(window.innerHeight / tileHeight) + 1);
            var sizes = [24, 48, 72, 96];
            for (var i = 0; i < sizes.length; i++) {
                if (sizes[i] >= needed) {
                    return sizes[i];
                }
            }
            return sizes[sizes.length - 1];
        },
        autoLoad: function () {
            if (!this.pagerNear()) {
                return;
            }
            var request = this.loadNextPage();
            if (!request) {
                return;
            }
            request.done(function (data) {
                // Keep filling while the end is still in view; a page with nothing new ends the chain.
                if (data.rendered && data.rendered.length && this.pagerNear()) {
                    window.setTimeout(this.autoLoad.bind(this), 50);
                }
            }.bind(this));
        },
        // Reloads the gallery with the current query string plus `overrides` (null removes a key).
        navigate: function (overrides) {
            var url = new URL(window.location.href);
            ['cursor', 'order-date', 'order-size'].forEach(function (k) { url.searchParams.delete(k); });
            Object.keys(overrides).forEach(function (k) {
                if (overrides[k] === null || overrides[k] === '') {
                    url.searchParams.delete(k);
                } else {
                    url.searchParams.set(k, overrides[k]);
                }
            });
            window.location.href = url.toString();
        },
        onOrderChoice: function (e) {
            var button = $(e.currentTarget);
            var overrides = {};
            overrides[button.data('orderField')] = button.data('orderValue');
            this.navigate(overrides);
        },
        applyCalendarRange: function () {
            var start = $('#calendarRangeStart').val();
            var end = $('#calendarRangeEnd').val() || start;
            if (!start) {
                return;
            }
            this.navigate({'calendar-start': start, 'calendar-end': end});
        },
        // Desktop: the months column folds away; remembered per browser. Phones use the drawer instead.
        toggleMonths: function () {
            var hidden = document.body.classList.toggle('months-collapsed');
            try { window.localStorage.setItem('miu.gallery.monthsHidden', hidden ? '1' : ''); } catch (err) { /* private mode */ }
        },
        restoreMonths: function () {
            var hidden = false;
            try { hidden = window.localStorage.getItem('miu.gallery.monthsHidden') === '1'; } catch (err) { /* private mode */ }
            document.body.classList.toggle('months-collapsed', hidden);
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
            $(modalEl).on('hidden.bs.modal', function () {
                $(modalEl).find('.preview-body').empty();
                if (this.cursor) {
                    this.cursor.trigger('focus');
                }
            }.bind(this));
            $(document).on('keydown', this.onPreviewKey.bind(this));
            // Touch: a horizontal swipe over the preview steps to the previous/next file.
            var swipe = null;
            modalEl.addEventListener('touchstart', function (e) {
                if (e.touches.length !== 1 || e.target.closest('.player-controls, .modal-footer, .modal-header')) {
                    swipe = null;
                    return;
                }
                var t = e.touches[0];
                swipe = {x: t.clientX, y: t.clientY, at: Date.now()};
            }, {passive: true});
            modalEl.addEventListener('touchend', function (e) {
                if (!swipe) {
                    return;
                }
                var t = e.changedTouches[0];
                var dx = t.clientX - swipe.x;
                var dy = t.clientY - swipe.y;
                var quick = Date.now() - swipe.at < 800;
                swipe = null;
                if (quick && Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) {
                    this.moveCursor(dx < 0 ? 1 : -1);
                }
            }.bind(this), {passive: true});
            modalEl.addEventListener('touchcancel', function () { swipe = null; }, {passive: true});
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
            if (delta > 0 && target > last) {
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
            if (next === last) {
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
            var isMedia = kind === 'image' || kind === 'video' || kind === 'audio';
            modal.find('[data-preview-copy]').attr('data-clipboard-text', new URL(isMedia ? url : tile.data('previewView'), window.location.href).href);
            modal.find('[data-preview-nav="-1"]').prop('disabled', tiles.index(tile) === 0);
            modal.find('[data-preview-nav="1"]').prop('disabled', tiles.index(tile) === tiles.length - 1 && !this.hasMorePages());
            var placeholder = function (icon, text) {
                return $('<div class="preview-placeholder">').append($('<i class="fa-solid" aria-hidden="true">').addClass(icon)).append($('<div>').text(text));
            };
            var fileIcon = tile.data('previewIcon') || 'fa-file';
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
            } else if (kind === 'text') {
                var pre = $('<pre class="preview-text" tabindex="0">').text('Loading…');
                body.append(pre);
                var limit = 512 * 1024;
                fetch(url, {credentials: 'same-origin'}).then(function (response) {
                    if (!response.ok) { throw new Error(response.status); }
                    return response.text();
                }).then(function (text) {
                    if (pre.parent().length === 0) { return; }
                    var truncated = text.length > limit;
                    pre.text(truncated ? text.slice(0, limit) : text);
                    if (truncated) {
                        pre.after($('<div class="preview-text-note">').text('Showing the first 512 KB. Download the file for the rest.'));
                    }
                }).catch(function () {
                    pre.replaceWith(placeholder(fileIcon, 'The text could not be loaded. Use Download to open it.'));
                });
            } else if (kind === 'hidden') {
                body.append(placeholder('fa-trash', 'This file is marked for deletion, so it cannot be previewed.'));
            } else {
                body.append(placeholder(fileIcon, 'No preview for this file type. Use Download to open it.'));
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
        // Selection is on once the Select button was pressed (any layout). Touch layouts used to
        // select on tap; now a tap opens the preview and Select enters selection mode, like the app.
        selectionActive: function () {
            return document.body.classList.contains('selection-mode');
        },
        toggleSelectionMode: function () {
            var on = document.body.classList.toggle('selection-mode');
            $('#gallerySelectBtn').attr('aria-pressed', on ? 'true' : 'false').toggleClass('is-active', on);
            if (!on) {
                this.clearSelection();
            }
            this.updateSelectionBar();
        },
        onTileTap: function (e) {
            if ($(e.target).closest('.itembox-controls, .itembox-select').length) {
                return;
            }
            if (this.selectionActive()) {
                var box = $(e.currentTarget).find('.itembox-check');
                box.prop('checked', !box.prop('checked')).trigger('change');
                return;
            }
            // Touch layouts have no hover controls: the tile itself opens the preview.
            if (this.selectionMedia.matches) {
                this.openPreview($(e.currentTarget));
            }
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
            $('body').toggleClass('has-selection', count > 0 && this.selectionActive());
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
            } else if (action === 'done') {
                if (document.body.classList.contains('selection-mode')) {
                    this.toggleSelectionMode();
                } else {
                    this.clearSelection();
                }
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
                this.repaintCalendar(this.calendarRangeStart, this.calendarRangeEnd);
                this.navigate({'calendar-start': this.calendarRangeStart, 'calendar-end': this.calendarRangeEnd});
                return;
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
            if (this.grid) {
                var url = new URL(action, window.location.href);
                url.searchParams.set('page-size', String(this.pageSizeFor()));
                action = url.pathname + url.search;
            }
            $('#fetch-in-progress').show();
            this.fetchLock = true;
            var self = this;
            return this.getData(action)
                .done(function (data) {
                    var container = $('#file-container');
                    $.each(data.rendered, function (i, o) {
                        var card = $(o).addClass('is-new');
                        var group = card.data('group');
                        var previous = container.children('[data-group]').last().data('group');
                        if (group && group !== previous) {
                            container.append($('<div class="gallery-group-header">').attr('data-group', group).text(group));
                        }
                        container.append(card);
                        window.setTimeout(function () { card.removeClass('is-new'); }, 400);
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