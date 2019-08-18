$(document).ready(function () {
    var UserGalleryControls = {
        fetchLock: false,
        initialize: function () {
            $('body').on('click', 'button[data-deleteid]', this.manageDeletion.bind(this));
            $('button[data-pagination-next]').on('click', this.fetchNextPage.bind(this));
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
