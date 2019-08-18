$(document).ready(function () {
    var AdminControlPanel = {
        initialize: function () {
            this.obtainFileSizes();
        },
        obtainFileSizes: function () {
            var cells = $('td[data-usersize]');

            this.getData('/endpoint/getstoragestats/').done(function (data, status, xhr) {
                if (data.status === "ok") {
                    var cellData = data.message;
                    $.each(cells, function () {
                        var cell = this;
                        var row = $(this).parent();
                        var userId = $(row).data("userid");
                        if (cellData[userId]) {
                            $(cell).html(cellData[userId]['total'] + ", " + cellData[userId]['amount'] + " files");
                        } else {
                            $(cell).html("No data.");
                        }
                    });
                }
            });
        },
        getData: function (url, data) {
            return $.ajax({
                type: 'GET',
                url: url,
                data: data
            });
        }
    };

    AdminControlPanel.initialize();
});