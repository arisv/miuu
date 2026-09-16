$(document).ready(function () {
    var UserGalleryControls = {
        fetchLock: false,
        calendarRangeStart: null,
        calendarRangeEnd: null,
        calendarPointer: 'start',
        initialize: function () {
            $('body').on('click', 'button[data-deleteid]', this.manageDeletion.bind(this));
            $('button[data-pagination-next]').on('click', this.fetchNextPage.bind(this));
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
            var button = e.currentTarget;
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