import { Modal } from 'bootstrap';

$(document).ready(function () {
    var AdminControlPanel = {
        initialize: function () {
            this.obtainFileSizes();
            $('body').on('click', 'button[data-toggle-user]', this.toggleUserActive.bind(this));
            $('body').on('click', 'button[data-reset-password]', this.resetUserPassword.bind(this));
            $('body').on('click', 'button[data-deleteaction]', this.toggleFileDeletion.bind(this));
            $('body').on('click', 'button[data-toggle-role]', this.toggleUserRole.bind(this));
            $('body').on('click', 'button[data-cancel-purge]', this.cancelPurge.bind(this));
            this.initializePurgeModal();
            this.initializeDeleteUser();
        },
        toggleUserRole: function (e) {
            var button = $(e.currentTarget);
            var row = button.closest('[data-userid]');
            var userId = button.data('toggle-role');
            var isAdmin = parseInt(button.attr('data-role'), 10) === 2;
            var login = row.find('[data-user-login]').text();
            var verb = isAdmin ? 'Demote "' + login + '" to a regular user?' : 'Promote "' + login + '" to admin? Admins can manage every user and file.';
            if (!window.confirm(verb)) {
                return;
            }
            button.prop('disabled', true);
            this.postData('/endpoint/setuserrole/', {'id': userId, 'role': isAdmin ? 1 : 2}).done(function (data) {
                if (data.status !== 'ok') {
                    alert(data.message || 'Unable to change role');
                    return;
                }
                var admin = data.role === 2;
                button.attr('data-role', data.role)
                    .attr('title', admin ? 'Demote to user' : 'Promote to admin')
                    .html('<i class="fa-solid ' + (admin ? 'fa-user' : 'fa-user-shield') + ' me-1" aria-hidden="true"></i>' + (admin ? 'Demote' : 'Promote'));
                row.find('.admin-chip.role-admin, .admin-chip.role-user')
                    .removeClass('role-admin role-user')
                    .addClass(admin ? 'role-admin' : 'role-user')
                    .text(admin ? 'Admin' : 'User');
            }).fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message;
                alert(message || 'Unable to change role');
            }).always(function () {
                button.prop('disabled', false);
            });
        },
        // Delete needs the username typed back; the server checks it again.
        initializeDeleteUser: function () {
            var modalEl = document.getElementById('deleteUserModal');
            if (!modalEl) {
                return;
            }
            var self = this;
            var modal = new Modal(modalEl);
            var $modal = $(modalEl);
            var input = $modal.find('#deleteUserConfirm');
            var submit = $modal.find('[data-delete-submit]');
            var error = $modal.find('[data-delete-error]');
            var target = null;
            $('body').on('click', 'button[data-delete-user]', function (e) {
                var button = $(e.currentTarget);
                var row = button.closest('[data-userid]');
                var sizeText = row.find('[data-usersize]').text();
                var filesMatch = /(\d+) files?/.exec(sizeText);
                target = {id: button.data('delete-user'), login: String(button.data('login')), row: row};
                $modal.find('[data-delete-login], [data-delete-login-title]').text(target.login);
                $modal.find('[data-delete-file-count]').text(filesMatch ? filesMatch[1] : '');
                input.val('');
                $modal.find('#deleteUserFiles').prop('checked', false);
                error.prop('hidden', true).text('');
                submit.prop('disabled', true);
                modal.show();
            });
            $modal.on('shown.bs.modal', function () { input.trigger('focus'); });
            input.on('input', function () {
                submit.prop('disabled', !target || input.val().trim() !== target.login);
            });
            $modal.find('#deleteUserForm').on('submit', function (e) {
                e.preventDefault();
                if (!target || input.val().trim() !== target.login) {
                    return;
                }
                submit.prop('disabled', true);
                self.postData('/endpoint/deleteuser/', {
                    'id': target.id,
                    'confirm': input.val().trim(),
                    'mark_files': $modal.find('#deleteUserFiles').is(':checked') ? 1 : 0
                }).done(function (data) {
                    if (data.status !== 'ok') {
                        error.text(data.message || 'Unable to delete user').prop('hidden', false);
                        return;
                    }
                    target.row.slideUp(200, function () { $(this).remove(); });
                    var counter = $('.admin-list-head .admin-chip');
                    counter.text(Math.max(parseInt(counter.text(), 10) - 1, 0));
                    modal.hide();
                }).fail(function (xhr) {
                    var message = xhr.responseJSON && xhr.responseJSON.message;
                    error.text(message || 'Unable to delete user').prop('hidden', false);
                    submit.prop('disabled', false);
                });
            });
        },
        toggleFileDeletion: function (e) {
            var button = $(e.currentTarget);
            var row = button.closest('[data-fileid]');
            var action = button.data('deleteaction');
            var fileId = button.data('deleteid');
            button.prop('disabled', true);
            this.postData('/endpoint/setdeletestatus/', {'id': fileId, 'action': action}).done(function (data) {
                if (data.status !== 'ok') {
                    alert('Unable to change file status');
                    return;
                }
                var marked = action === 'del';
                row.toggleClass('is-marked', marked);
                row.find('[data-marked-chip]').prop('hidden', !marked);
                row.find('button[data-deleteaction="del"]').prop('hidden', marked);
                row.find('button[data-deleteaction="undo"]').prop('hidden', !marked);
            }).fail(function () {
                alert('Unable to change file status');
            }).always(function () {
                button.prop('disabled', false);
            });
        },
        resetUserPassword: function (e) {
            var button = $(e.currentTarget);
            var row = button.closest('[data-userid]');
            var userId = button.data('reset-password');
            var login = row.find('[data-user-login]').text();
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
            var row = button.closest('[data-userid]');
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
                    .html('<i class="fa-solid ' + (active ? 'fa-ban' : 'fa-check') + ' me-1" aria-hidden="true"></i>' + (active ? 'Disable' : 'Enable'))
                    .toggleClass('btn-warning', active)
                    .toggleClass('btn-success', !active);
                row.find('[data-userstatus]').html(active
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
            var cells = $('[data-usersize]');

            this.getData('/endpoint/getstoragestats/').done(function (data, status, xhr) {
                if (data.status === "ok") {
                    var cellData = data.message;
                    $.each(cells, function () {
                        var cell = this;
                        var row = $(this).closest('[data-userid]');
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
        // Purge: queue every file of a user for deletion, confirmed by typing the username.
        initializePurgeModal: function () {
            var $modal = $('#purgeUserModal');
            if (!$modal.length) {
                return;
            }
            var modal = new Modal($modal[0]);
            var input = $modal.find('#purgeUserConfirm');
            var submit = $modal.find('[data-purge-submit]');
            var error = $modal.find('[data-purge-error]');
            var target = null;
            var self = this;
            $('body').on('click', 'button[data-purge-user]', function (e) {
                var button = $(e.currentTarget);
                target = {id: button.data('purge-user'), login: String(button.data('login')), row: button.closest('[data-userid]')};
                $modal.find('[data-purge-login], [data-purge-login-title]').text(target.login);
                $modal.find('[data-purge-file-count]').text(button.data('active'));
                input.val('');
                error.prop('hidden', true).text('');
                submit.prop('disabled', true);
                modal.show();
            });
            $modal.on('shown.bs.modal', function () { input.trigger('focus'); });
            input.on('input', function () {
                submit.prop('disabled', !target || input.val().trim() !== target.login);
            });
            $modal.find('#purgeUserForm').on('submit', function (e) {
                e.preventDefault();
                if (!target || input.val().trim() !== target.login) {
                    return;
                }
                submit.prop('disabled', true);
                self.postData('/endpoint/purgeuserfiles/', {'id': target.id, 'confirm': input.val().trim()}).done(function (data) {
                    if (data.status !== 'ok') {
                        error.text(data.message || 'Unable to purge').prop('hidden', false);
                        submit.prop('disabled', false);
                        return;
                    }
                    self.renderPurgeState(target.row, data.purge, target.login);
                    modal.hide();
                }).fail(function (xhr) {
                    var message = xhr.responseJSON && xhr.responseJSON.message;
                    error.text(message || 'Unable to purge').prop('hidden', false);
                    submit.prop('disabled', false);
                });
            });
        },
        cancelPurge: function (e) {
            var button = $(e.currentTarget);
            var login = String(button.data('login'));
            if (!window.confirm('Restore every file of "' + login + '"? This cancels the pending purge.')) {
                return;
            }
            var row = button.closest('[data-userid]');
            var self = this;
            button.prop('disabled', true);
            this.postData('/endpoint/cancelpurge/', {'id': button.data('cancel-purge')}).done(function (data) {
                if (data.status !== 'ok') {
                    window.alert(data.message || 'Unable to cancel the purge');
                    button.prop('disabled', false);
                    return;
                }
                self.renderPurgeState(row, data.purge, login);
            }).fail(function (xhr) {
                window.alert((xhr.responseJSON && xhr.responseJSON.message) || 'Unable to cancel the purge');
                button.prop('disabled', false);
            });
        },
        // Redraws the Files stat and swaps the Purge / Cancel purge button for the new state.
        renderPurgeState: function (row, purge, login) {
            var state = row.find('[data-purge-state]');
            if (purge && purge.pending) {
                var at = purge.purge_at ? new Date(purge.purge_at) : null;
                var hm = at ? String(at.getHours()).padStart(2, '0') + ':' + String(at.getMinutes()).padStart(2, '0') : '';
                state.html('<span class="badge bg-danger">Purge at ' + hm + '</span>');
            } else if (!purge || purge.total === 0) {
                state.html('<span class="text-secondary">none</span>');
            } else {
                state.text(purge.total + (purge.total === 1 ? ' file' : ' files'));
            }
            var userId = row.data('userid');
            var button = purge && purge.can_cancel
                ? $('<button type="button" class="btn btn-warning" title="Restore every file of this user"><i class="fa-solid fa-trash-arrow-up me-1" aria-hidden="true"></i>Cancel purge</button>')
                    .attr('data-cancel-purge', userId).attr('data-login', login)
                : $('<button type="button" class="btn btn-danger" title="Schedule the deletion of every file of this user"><i class="fa-solid fa-broom me-1" aria-hidden="true"></i>Purge</button>')
                    .attr('data-purge-user', userId).attr('data-login', login).attr('data-active', purge ? purge.total : 0)
                    .prop('disabled', !purge || purge.total === 0);
            row.find('button[data-purge-user], button[data-cancel-purge]').replaceWith(button);
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