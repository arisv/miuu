require('bootstrap/dist/css/bootstrap.min.css');
require('../css/dropzone.css');
require('../css/basic.css');
require('../css/app.css');
require('@fortawesome/fontawesome-free/js/all.js');
const $ = require('jquery');
require('bootstrap');
import * as Clipboard from './clipboard.min';

new Clipboard('.clipbutton');

$(document).ready(() => {
    $("#sidebarShowBtn, #sidebarCloseBtn").click((ev) => {
        $("#sidebar").toggle("slide");
    });
})