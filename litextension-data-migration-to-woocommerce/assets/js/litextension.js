/* global jQuery, litExtensionData */
jQuery(document).ready(function ($) {
    'use strict';

    var settings = window.litExtensionData || {};

    function addClassDisplay(child) {
        if (!child.hasClass('display-none')) {
            child.addClass('display-none');
            setTimeout(function () {
                child.css('display', 'none');
            }, 500);
        }
    }

    function removeClassDisplay(child) {
        if (child.hasClass('display-none')) {
            child.removeClass('display-none');
            setTimeout(function () {
                child.css('display', 'block');
            }, 500);
        }
    }

    /**
     * All calls back into WordPress go through admin-ajax.php with a nonce, so
     * they cannot be triggered from another site.
     */
    function wpAjax(action, data) {
        return $.ajax({
            type: 'POST',
            url: settings.ajaxUrl,
            dataType: 'json',
            data: $.extend({ action: action, nonce: settings.nonce }, data || {})
        });
    }

    $('#signin').on('submit', function (e) {
        e.preventDefault();

        var email = $('#email').val();
        var password = $('#password').val();
        var url = $('#url').val();

        $.ajax({
            type: 'POST',
            url: url,
            dataType: 'json',
            data: {
                email: email,
                password: password
            },
            success: function (response) {
                if (response.status === 'error') {
                    $('#msg').text(response.msg);
                    if ($('.lit-dialog').is(':hidden')) {
                        $('.lit-dialog').show();
                    }
                    return;
                }

                $('#security_token').val(response.security_token);
                addClassDisplay($('.lit-dialog'));
                $('#user-msg').text('You are logged in LitExtension as ' + email + '.');

                if ($('.lit-dialog-user').is(':hidden')) {
                    $('.lit-dialog-user').show();
                }

                $('#lit-signin').addClass('done');
                addClassDisplay($('#body-signin'));

                if ($('#lit-connect').hasClass('hidden-panel')) {
                    $('#lit-connect').removeClass('hidden-panel');
                }

                $('#body-connect').show();

                wpAjax('lit_save_session', {
                    email: email,
                    security_token: response.security_token
                });
            }
        });
    });

    $('#button-close').on('click', function () {
        addClassDisplay($('.lit-dialog'));
    });

    $('#user-button-logout').on('click', function () {
        addClassDisplay($('.lit-dialog-user'));

        if ($('#lit-signin').hasClass('done')) {
            $('#lit-signin').removeClass('done');
        }

        $('#lit-signin').show();
        removeClassDisplay($('#body-signin'));

        if (!$('#lit-connect').hasClass('hidden-panel')) {
            $('#lit-connect').addClass('hidden-panel');
        }

        $('#body-connect').hide();

        wpAjax('lit_clear_session');
    });

    $('#connect').on('submit', function (e) {
        e.preventDefault();

        var srcType = $('select#src-type option:checked').val();
        var srcUrl = $('#src-url').val();
        var extra = $('#connect-url').val();
        var securityToken = $('#security_token').val();
        var appUrl = settings.appUrl || 'https://app.litextension.com/';

        var url = appUrl + 'login-by-token?token=' + encodeURIComponent(securityToken) +
            '&redirect=' + encodeURIComponent('create-migration/' + srcType + '-to-woocommerce') +
            '&src_url=' + encodeURIComponent(srcUrl) +
            '&' + extra;

        window.open(url, '_blank', 'noopener,noreferrer');
    });
});
