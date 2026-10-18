import { ALIAS_PATTERN, joinName, nameProblem, portabilityWarnings } from './file-name-rules';

// Rename and custom-URL panel of the preview modal ("Spotlight"), with the name history and its
// reverts. It talks to the gallery endpoints under /endpoint/files/{id}/ with the page's CSRF
// token and hands every updated file to onFileUpdated, which refreshes the tile, the preview
// chrome and the selection bar.

/** Copies a file object from the endpoints onto its tile. Attributes, not .data(): jQuery would coerce names like "null" or "123". */
export function applyFileToTile(tile, file) {
    tile.attr({
        'data-preview-name': file.name,
        'data-name-base': file.base_name,
        'data-name-ext': file.name_extension || '',
        'data-file-alias': file.custom_url || '',
        'data-preview-share': file.urls.custom || '',
    });
    tile.removeData(['previewName', 'nameBase', 'nameExt', 'fileAlias', 'previewShare']);
    tile.find('.itembox-text p').first().text(file.name);
    tile.find('.itembox-check').attr('aria-label', 'Select ' + file.name);
}

export function createFileEditor(modalEl, { onFileUpdated }) {
    const modal = $(modalEl);
    const panel = modal.find('[data-file-edit]');
    const toggleButton = modal.find('[data-file-edit-toggle]');
    const token = modal.attr('data-file-edit-token') || '';
    const nameInput = panel.find('[data-file-edit-name]');
    const extLabel = panel.find('[data-file-edit-ext]');
    const nameSave = panel.find('[data-file-edit-save="name"]');
    const warning = panel.find('[data-file-edit-warning]');
    const aliasInput = panel.find('[data-file-edit-alias]');
    const status = panel.find('[data-file-edit-status]');
    const history = panel.find('[data-file-edit-history]');
    const historyList = panel.find('[data-file-edit-history-list]');
    let tile = null;
    // File id whose history is in the list; null until the section is first opened.
    let historyFor = null;
    // The exact name the user chose to save despite the portability warnings.
    let confirmedName = null;
    let swallowEscapeKeyup = false;
    let busy = false;

    if (modal.attr('data-file-edit-custom-urls') !== '1') {
        panel.find('[data-file-edit-alias-row]').remove();
    }

    const isOpen = () => !panel.prop('hidden');

    const send = (method, path, body) => $.ajax({
        type: method,
        url: '/endpoint/files/' + tile.attr('data-file-id') + path,
        data: body ? JSON.stringify(body) : undefined,
        contentType: 'application/json',
        dataType: 'json',
        headers: { 'X-CSRF-Token': token },
    });

    const errorText = (xhr, field) => {
        const error = xhr.responseJSON && xhr.responseJSON.error;
        if (error) {
            return (error.details && error.details[field] && error.details[field][0]) || error.message;
        }
        // A lapsed session redirects to the login page, which arrives as HTML.
        if (xhr.status === 200) {
            return 'Your session has expired. Reload the page and try again.';
        }
        return xhr.status === 0 ? 'The server could not be reached.' : 'Something went wrong. Try again.';
    };

    const showStatus = (text, kind) => {
        status.text(text || '').toggleClass('is-error', kind === 'error').toggleClass('is-ok', kind === 'ok');
    };

    const resetConfirmation = () => {
        confirmedName = null;
        warning.prop('hidden', true).find('ul').empty();
        nameSave.text('Save').removeClass('btn-warning').addClass('btn-primary');
    };

    const askConfirmation = (name, warnings) => {
        confirmedName = name;
        const list = warning.find('ul').empty();
        warnings.forEach((text) => list.append($('<li>').text(text)));
        warning.prop('hidden', false);
        nameSave.text('Save anyway').removeClass('btn-primary').addClass('btn-warning');
    };

    const fill = () => {
        const ext = tile.attr('data-name-ext') || '';
        nameInput.val(tile.attr('data-name-base') || '');
        extLabel.text(ext ? '.' + ext : '').prop('hidden', !ext);
        aliasInput.val(tile.attr('data-file-alias') || '').removeClass('is-invalid');
        resetConfirmation();
    };

    const historyRow = (entry) => {
        const when = new Date(entry.renamed_at);
        const meta = [when.toLocaleString()];
        if (entry.renamed_by) {
            meta.push(entry.renamed_by);
        }
        if (entry.revert) {
            meta.push('revert');
        }
        return $('<li class="preview-edit-history-entry">').attr('data-entry-id', entry.id).append(
            $('<div class="preview-edit-history-names">').append(
                $('<span class="preview-edit-history-old">').text(entry.old_name),
                $('<span class="preview-edit-history-arrow" aria-hidden="true">').text(' → '),
                $('<span class="visually-hidden">').text(' renamed to '),
                $('<span>').text(entry.new_name),
            ),
            $('<div class="preview-edit-history-meta">').append(
                $('<time>').attr('datetime', entry.renamed_at).text(meta.join(' · ')),
                $('<button type="button" class="btn btn-secondary btn-sm" data-file-edit-revert>')
                    .attr('data-old-name', entry.old_name)
                    .attr('title', 'Rename back to ' + entry.old_name)
                    .text('Revert'),
            ),
        );
    };

    // Reverting to the name the file already has would do nothing.
    const syncRevertButtons = () => {
        const current = tile ? tile.attr('data-preview-name') : null;
        historyList.find('[data-file-edit-revert]').each(function () {
            this.disabled = this.getAttribute('data-old-name') === current;
        });
    };

    const loadHistory = () => {
        const fileId = tile.attr('data-file-id');
        historyFor = fileId;
        historyList.empty().append($('<li class="preview-edit-history-note">').text('Loading…'));
        send('GET', '/name-history')
            .done((r) => {
                if (historyFor !== fileId) {
                    return;
                }
                historyList.empty();
                if (!r.history.length) {
                    historyList.append($('<li class="preview-edit-history-note">').text('No renames yet.'));
                }
                r.history.forEach((entry) => historyList.append(historyRow(entry)));
                syncRevertButtons();
            })
            .fail((xhr) => {
                if (historyFor === fileId) {
                    historyFor = null;
                    historyList.empty().append($('<li class="preview-edit-history-note is-error">').text(errorText(xhr, 'history')));
                }
            });
    };

    const resetHistory = () => {
        historyFor = null;
        history.prop('open', false);
        historyList.empty();
    };

    const recordChange = (entry) => {
        if (!entry || historyFor !== tile.attr('data-file-id')) {
            return;
        }
        historyList.find('.preview-edit-history-note').remove();
        historyList.prepend(historyRow(entry));
    };

    const updated = (file, message, entry) => {
        onFileUpdated(tile, file);
        fill();
        showStatus(message, 'ok');
        recordChange(entry);
        syncRevertButtons();
    };

    const run = (request, field) => {
        busy = true;
        panel.attr('aria-busy', 'true');
        return request
            .fail((xhr) => showStatus(errorText(xhr, field), 'error'))
            .always(() => {
                busy = false;
                panel.removeAttr('aria-busy');
            });
    };

    const saveName = () => {
        if (busy || !tile) {
            return;
        }
        const base = nameInput.val();
        const ext = tile.attr('data-name-ext') || null;
        const problem = nameProblem(base, ext);
        if (problem) {
            showStatus(problem, 'error');
            return;
        }
        const name = joinName(base, ext);
        if (name === tile.attr('data-preview-name')) {
            showStatus('The name is unchanged.');
            return;
        }
        const warnings = portabilityWarnings(name);
        if (warnings.length && confirmedName !== name) {
            showStatus('');
            askConfirmation(name, warnings);
            return;
        }
        run(send('POST', '/rename', { base_name: base }), 'base_name')
            .done((r) => updated(r.file, r.changed ? 'Renamed.' : 'The name is unchanged.', r.entry));
    };

    const saveAlias = () => {
        if (busy || !tile) {
            return;
        }
        const alias = aliasInput.val().trim();
        const current = tile.attr('data-file-alias') || '';
        if (alias === current) {
            showStatus(alias ? 'The custom URL is unchanged.' : 'There is no custom URL to remove.');
            return;
        }
        if (alias === '') {
            removeAlias();
            return;
        }
        if (!ALIAS_PATTERN.test(alias)) {
            showStatus('Use 1 to 64 letters and digits (A–Z, a–z, 0–9).', 'error');
            return;
        }
        run(send('POST', '/custom-url', { custom_url: alias }), 'custom_url')
            .done((r) => updated(r.file, 'Copy link now uses ' + r.file.urls.custom));
    };

    const removeAlias = () => {
        if (busy || !tile) {
            return;
        }
        if (!tile.attr('data-file-alias')) {
            aliasInput.val('');
            showStatus('There is no custom URL to remove.');
            return;
        }
        run(send('DELETE', '/custom-url'), 'custom_url')
            .done((r) => updated(r.file, 'Custom URL removed. Copy link uses the default link again.'));
    };

    // Enter saves, except while an IME is composing (Enter then confirms the composition).
    const onEnter = (action) => (e) => {
        if (e.key === 'Enter' && !e.isComposing && e.keyCode !== 229) {
            e.preventDefault();
            action();
        }
    };

    nameInput.on('keydown', onEnter(saveName)).on('input', () => {
        resetConfirmation();
        showStatus('');
    });
    nameSave.on('click', saveName);
    aliasInput.on('keydown', onEnter(saveAlias)).on('input', () => {
        const value = aliasInput.val().trim();
        aliasInput.toggleClass('is-invalid', value !== '' && !ALIAS_PATTERN.test(value));
        showStatus('');
    });
    panel.find('[data-file-edit-save="alias"]').on('click', saveAlias);
    panel.find('[data-file-edit-remove]').on('click', removeAlias);
    // "toggle" does not bubble, so it is bound on the element itself.
    history.on('toggle', () => {
        if (history.prop('open') && tile && historyFor !== tile.attr('data-file-id')) {
            loadHistory();
        }
    });
    historyList.on('click', '[data-file-edit-revert]', function () {
        if (busy || !tile) {
            return;
        }
        const entryId = $(this).closest('[data-entry-id]').attr('data-entry-id');
        run(send('POST', '/name-history/' + entryId + '/revert'), 'history')
            .done((r) => updated(r.file, r.changed ? 'Name restored.' : 'The name is unchanged.', r.entry));
    });

    const focusField = (field) => {
        const input = field === 'alias' && aliasInput.length ? aliasInput : nameInput;
        const go = () => input.trigger('focus').trigger('select');
        // Bootstrap focuses the dialog once it is shown, which would undo an earlier focus.
        if (modal.data('bsShown')) {
            go();
        } else {
            modal.one('shown.bs.modal', go);
        }
    };

    const editor = {
        isOpen,
        open(field) {
            if (!tile) {
                return;
            }
            panel.prop('hidden', false);
            modal.find('.preview-content').addClass('is-editing');
            toggleButton.attr('aria-expanded', 'true').addClass('active');
            focusField(field);
        },
        close({ restoreFocus = true } = {}) {
            if (!isOpen()) {
                return;
            }
            panel.prop('hidden', true);
            modal.find('.preview-content').removeClass('is-editing');
            toggleButton.attr('aria-expanded', 'false').removeClass('active');
            if (tile) {
                fill();
            }
            showStatus('');
            if (restoreFocus) {
                toggleButton.trigger('focus');
            }
        },
        toggle() {
            isOpen() ? editor.close() : editor.open('name');
        },
        /** Follows the preview to another file; unsaved input is dropped, the panel stays open. */
        sync(newTile) {
            tile = newTile;
            fill();
            resetHistory();
            showStatus('');
        },
        /**
         * Escape closes the warnings, then the panel, and only then (unhandled) the modal.
         * The matching keyup is swallowed too, or it would close the modal right after.
         */
        handleEscape(e) {
            if (e.type === 'keyup') {
                const swallow = swallowEscapeKeyup;
                swallowEscapeKeyup = false;
                return swallow;
            }
            if (!isOpen()) {
                return false;
            }
            swallowEscapeKeyup = true;
            if (e.isComposing) {
                return true;
            }
            if (!warning.prop('hidden')) {
                resetConfirmation();
            } else {
                editor.close();
            }
            return true;
        },
    };

    modal.on('shown.bs.modal', () => modal.data('bsShown', true));
    modal.on('hidden.bs.modal', () => {
        modal.data('bsShown', false);
        editor.close({ restoreFocus: false });
    });
    toggleButton.on('click', () => editor.toggle());
    return editor;
}
