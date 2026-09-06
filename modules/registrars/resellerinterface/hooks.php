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
    echo json_encode(
        resellerinterface_TestConnection($params),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
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
    function findModuleScope() {
        var $marker = $('#ri-config-root');
        var $start = $marker.closest('tr');
        var $end = $('#ri-test-wrap').closest('tr');
        if ($start.length && $end.length && $start.parent()[0] === $end.parent()[0]) {
            var $rows = $();
            $start.nextAll('tr').addBack().each(function () {
                $rows = $rows.add(this);
                if (this === $end[0]) {
                    return false;
                }
            });
            if ($rows.length) {
                return $rows;
            }
        }

        if ($marker.length) {
            var $scope = $marker.closest('table, .panel, fieldset');
            if ($scope.length) {
                return $scope;
            }
        }

        var $hint = $('td, th, label, small, span, p, div').filter(function () {
            var text = $(this).text();
            return text.indexOf('/stable/reseller/login') !== -1
                || text.indexOf('$client->login') !== -1;
        }).first();
        if ($hint.length) {
            return $hint.closest('table, .panel, fieldset');
        }

        return $();
    }

    function fieldNames(key) {
        return [
            key,
            'fields[' + key + ']',
            'fields[resellerinterface][' + key + ']',
            'resellerinterface_' + key
        ];
    }

    function findNamed($root, key) {
        var names = fieldNames(key);
        for (var i = 0; i < names.length; i++) {
            var $el = $root.find(
                'input[name="' + names[i] + '"], select[name="' + names[i] + '"], textarea[name="' + names[i] + '"]'
            );
            if ($el.length) {
                return $el.first();
            }
        }
        return $();
    }

    function findField($scope, key) {
        if (key === 'Username') {
            var $local = findNamed($('#ri-config-root').closest('td, .fieldarea, .form-group, tr'), key);
            if ($local.length) {
                return $local;
            }
        }
        return findNamed($scope, key);
    }

    var $scope = findModuleScope();
    if (!$scope.length) {
        return;
    }

    if (!$scope.find('#ri-test-connection').length) {
        $scope.append(
            '<div id="ri-test-wrap" style="margin:15px 0;">' +
                '<button type="button" class="btn btn-default" id="ri-test-connection">' +
                    '<i class="fas fa-plug"></i> Verbindung testen</button>' +
                '<div id="ri-test-result" style="margin-top:10px;display:none;"></div>' +
            '</div>'
        );
    }

    var $btn = $scope.find('#ri-test-connection').first();
    var $result = $scope.find('#ri-test-result').first();
    if (!$result.length) {
        $result = $('<div id="ri-test-result" style="margin-top:10px;display:none;"></div>');
        $btn.after($result);
    }

    $btn.off('click.riTest').on('click.riTest', function () {
        var $form = $scope.closest('form');
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
            'Username', 'Password', 'TotpCode',
            'ResellerId', 'DefaultHandleTag', 'DefaultRedirectMode', 'DebugMode'
        ];
        $.each(known, function (_, key) {
            var $field = findField($scope, key);
            if (!$field.length) {
                return;
            }
            if ($field.is(':checkbox')) {
                postData['ri_' + key] = $field.is(':checked') ? ($field.val() || 'on') : '';
                return;
            }
            var value = $field.val();
            if ((key === 'Password' || key === 'TotpCode') && (!value || /^[\.●•*]+$/.test(value))) {
                return;
            }
            postData['ri_' + key] = value;
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
