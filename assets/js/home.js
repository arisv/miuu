require('../css/dropzone.css');
require('../css/basic.css');

import * as Dropzone from './dropzone.js';

Dropzone.autoDiscover = false;
$(function () {
    const dz = new Dropzone('div#dropzonefield', {
        url: '/endpoint/dropzone/',
        paramName: 'meowfile',
        maxFileSize: 100,
        maxFiles: 4,
        timeout: 0,
        dictDefaultMessage:
            '<span class="dz-icon"><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i></span>' +
            '<span class="dz-title">Drop files here</span>' +
            '<span class="dz-hint">or click to browse \u00b7 up to 4 files at a time</span>'
    });
    $('#upload-legacy').hide();
    $('#upload-dropzone').show();
    dz.on("success", function (file, reply) {
        console.log(reply);
        if (reply.success) {
            if (!reply.thumbnailable && reply.icon) {
                const ext = (file.name.split('.').pop() || '').slice(0, 5);
                $(file.previewTemplate).find('.dz-image').empty().append(
                    $('<div class="dz-file-icon" aria-hidden="true">').append($('<i class="fa-solid" aria-hidden="true">').addClass(reply.icon)).append($('<span>').text(ext))
                );
            }
            const actions = $('<div class="btn-group btn-group-sm dz-actions" role="group" aria-label="Uploaded file actions">');
            actions.append(
                $('<button type="button" class="btn clipbutton" title="Copy link"><i class="fa-solid fa-link me-1" aria-hidden="true"></i><span class="clip-label">Copy</span></button>').attr('data-clipboard-text', reply.copy || reply.download),
                $('<a class="btn btn-secondary" title="Open the file page"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i><span class="visually-hidden">Open</span></a>').attr('href', reply.view)
            );
            $(file.previewTemplate).append($('<div class="text-center">').append(actions));
        }
    });
});
