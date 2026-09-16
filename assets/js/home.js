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
            $(file.previewTemplate).append(
                $('<div class="text-center"><button class="btn clipbutton" data-clipboard-text="' + reply.download + '">Copy Link</button></div>')
            );
        }
    });
});
