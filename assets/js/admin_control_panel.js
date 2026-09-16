$(document).ready(function () {
    var AdminControlPanel = {
        initialize: function () {
            this.obtainFileSizes();
            $('body').on('click', 'button[data-toggle-user]', this.toggleUserActive.bind(this));
            $('body').on('click', 'button[data-reset-password]', this.resetUserPassword.bind(this));
        },
        resetUserPassword: function (e) {
            var button = $(e.currentTarget);
            var row = button.closest('tr');
            var userId = button.data('reset-password');
            var login = row.children('td').eq(1).text();
            if (!window.confirm('Reset password for "' + login + '"? The current password stops working immediately.')) {
                return;
            }
            button.prop('disabled', true);
            this.postData('/endpoint/resetuserpassword/', {'id': userId}).done(function (data) {
                if (data.status !== 'ok') {
                    alert(data.message || 'Unable to reset password');
                    return;
                }
                var result = row.find('[data-password-result]');
                result.empty()
                    .append('New password (shown once): ')
                    .append($('<code>').text(data.password))
                    .prop('hidden', false);
            }).fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message;
                alert(message || 'Unable to reset password');
            }).always(function () {
                button.prop('disabled', false);
            });
        },
        toggleUserActive: function (e) {
            var button = $(e.currentTarget);
            var row = button.closest('tr');
            var userId = button.data('toggle-user');
            var currentlyActive = parseInt(button.attr('data-active'), 10) === 1;
            button.prop('disabled', true);
            this.postData('/endpoint/setuseractive/', {
                'id': userId,
                'active': currentlyActive ? 0 : 1
            }).done(function (data) {
                if (data.status !== 'ok') {
                    alert(data.message || 'Unable to change user status');
                    return;
                }
                var active = data.active;
                button.attr('data-active', active ? 1 : 0)
                    .text(active ? 'Disable' : 'Enable')
                    .toggleClass('btn-warning', active)
                    .toggleClass('btn-success', !active);
                row.find('td[data-userstatus]').html(active
                    ? '<span class="badge bg-success">Active</span>'
                    : '<span class="badge bg-secondary">Disabled</span>');
            }).fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message;
                alert(message || 'Unable to change user status');
            }).always(function () {
                button.prop('disabled', false);
            });
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
        postData: function (url, data) {
            return $.ajax({
                type: 'POST',
                url: url,
                data: data
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