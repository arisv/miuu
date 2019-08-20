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
                $(el).find(":input").filter(function(){ return !this.value; }).attr("disabled", "disabled");
                return true;
            }.bind(this));
            this.applyExistingFilter(__filter);
        },
        calendarHighlight: function (e) {
            var el = e.currentTarget;
            var date = $(el).data('date');

            if (this.calendarPointer === 'start') {
                this.calendarRangeStart = date;
                this.calendarPointer = 'end';
            } else if (this.calendarPointer === 'end') {
                this.calendarRangeEnd = date;
                this.calendarPointer = 'start';
            }
            this.repaintCalendar(this.calendarRangeStart, this.calendarRangeEnd);
        },
        repaintCalendar: function(rangeStart, rangeEnd) {
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
            $(pressed).prop('disabled', true);
            this.postData('/endpoint/setdeletestatus/', {'id': itemId, 'action': action})
                .done(function (data, status, xhr) {
                    if (data.status === 'ok') {
                        $(pressed).prop('disabled', false);
                        $(pressed).hide();
                        $(newButton).show();
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
