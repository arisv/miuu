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
        },
        calendarHighlight: function (e) {
            var el = e.currentTarget;
            var date = $(el).data('date');
            var months = $('[data-date]');

            if (this.calendarPointer === 'start') {
                this.calendarRangeStart = date;
                this.calendarPointer = 'end';
            } else if (this.calendarPointer === 'end') {
                this.calendarRangeEnd = date;
                this.calendarPointer = 'start';
            }

            $(months).removeClass('calendar-highlight');
            var activeSelection = false;
            var firstDate = Date.parse(this.calendarRangeStart);
            var secondDate = Date.parse(this.calendarRangeEnd);
            console.log({'start': this.calendarRangeStart, 'end': this.calendarRangeEnd});
            console.log({'first': firstDate, 'second': secondDate});

            if (secondDate > firstDate) {
                var temp = firstDate;
                firstDate = secondDate;
                secondDate = temp;
            }
            console.log({'first': firstDate, 'second': secondDate});
            $(months).each(function (i, o) {
                var thisDate = Date.parse($(o).data('date'));
                if (thisDate <= firstDate && thisDate >= secondDate) {
                    $(o).addClass('calendar-highlight');
                }
            }.bind(this));

        },
        manageDeletion: function (e) {
            var pressed = e.currentTarget;
            console.log(pressed);
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
        }
    };

    UserGalleryControls.initialize();
});
