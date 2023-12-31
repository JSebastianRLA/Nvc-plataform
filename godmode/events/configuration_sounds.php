<?php
/**
 * Audit log View.
 *
 * @category   Audit log
 * @package    Pandora FMS
 * @subpackage Community
 * @version    1.0.0
 * @license    See below
 *
 *    ______                 ___                    _______ _______ ________
 *   |   __ \.-----.--.--.--|  |.-----.----.-----. |    ___|   |   |     __|
 *  |    __/|  _  |     |  _  ||  _  |   _|  _  | |    ___|       |__     |
 * |___|   |___._|__|__|_____||_____|__| |___._| |___|   |__|_|__|_______|
 *
 * ============================================================================
 * Copyright (c) 2005-2022 Artica Soluciones Tecnologicas
 * Please see http://pandorafms.org for full contribution list
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation for version 2.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * ============================================================================
 */

// Begin.
global $config;

require_once $config['homedir'].'/include/class/EventSound.class.php';

$ajaxPage = 'godmode/events/configuration_sounds';

// Control call flow.
try {
    // User access and validation is being processed on class constructor.
    $controller = new EventSound($ajaxPage);
} catch (Exception $e) {
    if ((bool) is_ajax() === true) {
        echo json_encode(['error' => '[EventSound]'.$e->getMessage() ]);
        exit;
    } else {
        echo '[EventSound]'.$e->getMessage();
    }

    // Stop this execution, but continue 'globally'.
    return;
}

// AJAX controller.
if ((bool) is_ajax() === true) {
    $method = get_parameter('method');

    if (method_exists($controller, $method) === true) {
        if ($controller->ajaxMethod($method) === true) {
            $controller->{$method}();
        } else {
            $controller->error('Unavailable method.');
        }
    } else {
        $controller->error('Method not found. ['.$method.']');
    }

    // Stop any execution.
    exit;
} else {
    // Run.
    $controller->run();
}
