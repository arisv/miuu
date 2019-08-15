require('../css/dropzone.css');
require('../css/basic.css');

import * as Dropzone from './dropzone.js';
import * as Clipboard from './clipboard.min';

Dropzone.autoDiscover = false;
$(function () {
    const dz = new Dropzone('div#dropzonefield', {
        url: '/endpoint/dropzone/',
        paramName: 'meowfile',
        maxFileSize: 100,
        maxFiles: 4,
        timeout: 0
    });
    new Clipboard('.clipbutton');
    $('#upload-legacy').hide();
    $('#upload-dropzone').show();
    dz.on("success", function (file, reply) {
        console.log(reply);
        if (reply.success) {
            $(file.previewTemplate).append(
                $('<button class="btn btn-default clipbutton" data-clipboard-text="' + reply.download + '">Copy URL</button>')
            );
        }
    });
});
