<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/ModuleConfig.php';

use WHMCS\Module\Registrar\Resellerinterface\ModuleConfig;

/**
 * Handle AJAX connection test from the registrar configuration form.
 */
add_hook('AdminAreaPage', 1, function (array $vars): void {
    if (($_REQUEST['resellerinterface_action'] ?? '') !== 'testconnection') {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');

    $postedToken = (string) ($_POST['token'] ?? '');
    $tokenValid = $postedToken !== '';

    if (class_exists('\WHMCS\Session') && method_exists('\WHMCS\Session', 'verifyToken')) {
        try {
            $tokenValid = (bool) \WHMCS\Session::verifyToken();
        } catch (\Throwable $e) {
            $tokenValid = $postedToken !== '';
        }
    }

    if (!$tokenValid) {
        echo json_encode(['error' => 'Ungültiges Sicherheitstoken. Bitte Seite neu laden.']);
        exit;
    }

    if (!function_exists('resellerinterface_TestConnection')) {
        require_once __DIR__ . '/resellerinterface.php';
    }

    $params = ModuleConfig::getParamsFromRequest($_POST);
    echo json_encode(resellerinterface_TestConnection($params));
    exit;
});

/**
 * Bind the connection-test button that is rendered in the module settings.
 * Also injects the button if the config field HTML was escaped by the template.
 */
add_hook('AdminAreaFooterOutput', 1, function (array $vars): string {
    return <<<'HTML'
<script>
jQuery(function ($) {
    var fieldNames = [
        'Username',
        'fields[Username]',
        'fields[resellerinterface][Username]',
        'resellerinterface_Username'
    ];

    function findUsernameInput() {
        for (var i = 0; i < fieldNames.length; i++) {
            var $el = $('input[name="' + fieldNames[i] + '"]');
            if ($el.length) {
                return $el.first();
            }
        }
        return $();
    }

    var $username = findUsernameInput();
    if (!$username.length) {
        return;
    }

    if (!$('#ri-test-connection').length) {
        var $wrap = $(
            '<div id="ri-test-wrap" style="margin:15px 0;">' +
                '<button type="button" class="btn btn-default" id="ri-test-connection">' +
                    '<i class="fas fa-plug"></i> Verbindung testen</button>' +
                '<div id="ri-test-result" style="margin-top:10px;display:none;"></div>' +
            '</div>'
        );
        var $save = $('input[value="Save Changes"], button:contains("Save Changes"), input[value="Änderungen speichern"], button:contains("Änderungen speichern")').filter(':visible').last();
        if ($save.length) {
            $save.after($wrap);
        } else {
            $username.closest('form, table, .panel, div').first().append($wrap);
        }
    }

    var $btn = $('#ri-test-connection');
    var $result = $('#ri-test-result');
    if (!$result.length) {
        $result = $('<div id="ri-test-result" style="margin-top:10px;display:none;"></div>');
        $btn.after($result);
    }

    $btn.off('click.riTest').on('click.riTest', function () {
        var $form = $username.closest('form');
        var token = '';
        if ($form.length) {
            token = $form.find('input[name="token"]').val() || '';
        }
        if (!token) {
            token = $('input[name="token"]').first().val() || '';
        }

        var postData = {
            resellerinterface_action: 'testconnection',
            token: token
        };

        var known = [
            'Username', 'Password', 'TotpCode', 'ApiUrl', 'ApiPrefix',
            'ResellerId', 'DefaultHandleTag', 'DefaultRedirectMode', 'DebugMode'
        ];
        var $scope = $username.closest('table, .panel, fieldset, form');
        $.each(known, function (_, key) {
            var $field = $scope.find(
                'input[name="' + key + '"], select[name="' + key + '"], textarea[name="' + key + '"],' +
                'input[name="fields[' + key + ']"], select[name="fields[' + key + ']"]'
            ).first();
            if (!$field.length) {
                return;
            }
            if ($field.is(':checkbox')) {
                postData['ri_' + key] = $field.is(':checked') ? ($field.val() || 'on') : '';
                return;
            }
            postData['ri_' + key] = $field.val();
        });

        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Teste Verbindung...');
        $result.hide().removeClass('alert alert-success alert-danger').empty();

        $.ajax({
            url: window.location.href.split('#')[0],
            method: 'POST',
            data: postData,
            dataType: 'json'
        }).done(function (response) {
            if (response && response.success) {
                $result.addClass('alert alert-success').text(response.success).show();
            } else if (response && response.error) {
                $result.addClass('alert alert-danger').text(response.error).show();
            } else {
                $result.addClass('alert alert-danger').text('Unbekannte Antwort vom Server.').show();
            }
        }).fail(function (xhr) {
            var message = 'Verbindungstest fehlgeschlagen.';
            if (xhr.responseJSON && xhr.responseJSON.error) {
                message = xhr.responseJSON.error;
            } else if (xhr.responseText) {
                try {
                    var parsed = JSON.parse(xhr.responseText);
                    if (parsed.error) {
                        message = parsed.error;
                    }
                } catch (e) {
                    if (xhr.status) {
                        message += ' (HTTP ' + xhr.status + ')';
                    }
                }
            }
            $result.addClass('alert alert-danger').text(message).show();
        }).always(function () {
            $btn.prop('disabled', false).html('<i class="fas fa-plug"></i> Verbindung testen');
        });
    });
});
</script>
HTML;
});
