$(document).ready(function () {
    var UserGalleryControls = {
        initialize: function () {
            $('button[data-deleteid]').on('click', this.manageDeletion.bind(this));
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
        postData: function (url, data) {
            return $.ajax({
                type: 'POST',
                url: url,
                data: data
            });
        }
    };


    UserGalleryControls.initialize();
});
