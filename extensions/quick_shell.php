<?php
/**
 * Quick Shell extension.
 *
 * @category   Extension
 * @package    Pandora FMS
 * @subpackage QuickShell
 * @version    1.0.0
 * @license    See below
 *
 *    ______                 ___                    _______ _______ ________
 *   |   __ \.-----.--.--.--|  |.-----.----.-----. |    ___|   |   |     __|
 *  |    __/|  _  |     |  _  ||  _  |   _|  _  | |    ___|       |__     |
 * |___|   |___._|__|__|_____||_____|__| |___._| |___|   |__|_|__|_______|
 *
 * ============================================================================
 * Copyright (c) 2005-2021 Artica Soluciones Tecnologicas
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

require_once $config['homedir'].'/include/functions_agents.php';
require_once $config['homedir'].'/godmode/wizards/Wizard.main.php';


/**
 * Undocumented function
 *
 * @param string $url    Url.
 * @param array  $params Params.
 *
 * @return mixed Result
 */
function curl(string $url, array $params)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    $get_result = curl_exec($ch);

    curl_close($ch);

    return $get_result;
}


/**
 * Show Quick Shell interface.
 *
 * @return void
 */
function quickShell()
{
    global $config;

    check_login();

    if (check_acl($config['id_user'], 0, 'PM') === false) {
        db_pandora_audit(
            AUDIT_LOG_ACL_VIOLATION,
            'Trying to access Profile Management'
        );
        include 'general/noaccess.php';
        return;
    }

    $agent_id = get_parameter('id_agente', 0);
    $username = get_parameter('username', null);
    $method = get_parameter('method', null);
    $method_port = get_parameter('port', null);

    // Retrieve main IP Address.
    $address = agents_get_address($agent_id);

    ui_require_css_file('wizard');
    ui_require_css_file('discovery');

    // Settings.
    // WebSocket host, where client should connect.
    if (isset($config['ws_port']) === false) {
        config_update_value('ws_port', 8080);
    }

    if (empty($config['ws_proxy_url']) === true) {
        $ws_url = 'http://'.$_SERVER['SERVER_ADDR'].':'.$config['ws_port'];
    } else {
        preg_match('/\/\/(.*)/', $config['ws_proxy_url'], $matches);
        if (isset($_SERVER['HTTPS']) === true) {
            $ws_url = 'https://'.$matches[1];
        } else {
            $ws_url = 'http://'.$matches[1];
        }
    }

    // Gotty settings. Internal communication (WS).
    if (isset($config['gotty_host']) === false) {
        config_update_value('gotty_host', '127.0.0.1');
    }

    if (isset($config['gotty_telnet_port']) === false) {
        config_update_value('gotty_telnet_port', 8082);
    }

    if (isset($config['gotty_ssh_port']) === false) {
        config_update_value('gotty_ssh_port', 8081);
    }

    // Context to allow self-signed certs.
    $context = stream_context_create(
        [
            'http' => [ 'method' => 'GET'],
            'ssl'  => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]
    );

    // Username. Retrieve from form.
    if (empty($username) === true) {
        // No username provided, ask for it.
        $wiz = new Wizard();

        $test = curl($ws_url, []);
        if ($test === false) {
            ui_print_error_message(__('WebService engine has not been started, please check documentation.'));
            $wiz->printForm(
                [
                    'form' => [
                        'method' => 'POST',
                        'action' => '#',
                        'id'     => 'retry_form',
                    ],
                ]
            );

            html_print_action_buttons(
                html_print_submit_button(
                    __('Retry'),
                    'submit',
                    false,
                    [
                        'icon' => 'next',
                        'form' => 'retry_form',
                    ],
                    true
                )
            );
            return;
        }

        $wiz->printForm(
            [
                'form'   => [
                    'action' => '#',
                    'class'  => 'wizard',
                    'method' => 'post',
                    'id'     => 'connect_form',
                ],
                'inputs' => [
                    [
                        'label'     => __('Username'),
                        'arguments' => [
                            'type' => 'text',
                            'name' => 'username',
                        ],
                    ],
                    [
                        'label'     => __('Port'),
                        'arguments' => [
                            'type'  => 'text',
                            'id'    => 'port',
                            'name'  => 'port',
                            'value' => 22,
                        ],
                    ],
                    [
                        'label'     => __('Method'),
                        'arguments' => [
                            'type'   => 'select',
                            'name'   => 'method',
                            'fields' => [
                                'ssh'    => __('SSH'),
                                'telnet' => __('Telnet'),
                            ],
                            'script' => "p=22; if(this.value == 'telnet') { p=23; } $('#text-port').val(p);",
                        ],
                    ],
                ],
            ],
            false,
            true
        );

        html_print_action_buttons(
            html_print_submit_button(
                __('Connect'),
                'submit',
                false,
                [
                    'icon' => 'cog',
                    'form' => 'connect_form',
                ],
                true
            )
        );
        return;
    }

    // Initialize Gotty Client.
    $host = $config['gotty_host'];
    if ($method == 'ssh') {
        // SSH.
        $port = $config['gotty_ssh_port'];
        $command_arguments = "var args = '?arg=".$username.'@'.$address;
        $command_arguments .= '&arg=-p '.$method_port."';";
    } else if ($method == 'telnet') {
        // Telnet.
        $port = $config['gotty_telnet_port'];
        $username = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $username);
        $command_arguments = "var args = '?arg=-l ".$username;
        $command_arguments .= '&arg='.$address;
        $command_arguments .= '&arg='.$method_port."&arg=-E';";
    } else {
        ui_print_error_message(__('Please use SSH or Telnet.'));
        return;
    }

    // If rediretion is enabled, we will try to connect using
    // http:// or https:// endpoint.
    $test = get_headers($ws_url, false, $context);
    if ($test === false) {
        if (empty($wiz) === true) {
            $wiz = new Wizard();
        }

        ui_print_error_message(__('WebService engine has not been started, please check documentation.'));
        echo $wiz->printGoBackButton('#');
        return;
    }

    // Check credentials.
    $auth_str = '';
    $gotty_url = $host.':'.$port;
    if (empty($config['gotty_user']) === false
        && empty($config['gotty_pass']) === false
    ) {
        $auth_str = io_safe_output($config['gotty_user']);
        $auth_str .= ':'.io_output_password($config['gotty_pass']);
        $gotty_url = $auth_str.'@'.$host.':'.$port;
    }

    $r = file_get_contents('http://'.$gotty_url.'/js/hterm.js');
    if (empty($r) === true) {
        if (empty($wiz) === true) {
            $wiz = new Wizard();
        }

        ui_print_error_message(__('WebService engine is not working properly, please check documentation.'));
        echo $wiz->printGoBackButton('#');
        return;
    }

    // Override gotty client settings.
    if (empty($auth_str) === true) {
        $r .= "var gotty_auth_token = '';";
    } else {
        $r .= "var gotty_auth_token = '";
        $r .= $auth_str."';";
    }

    // Set websocket target and method.
    $gotty = file_get_contents('http://'.$gotty_url.'/js/gotty.js');
    $url = "var url = (httpsEnabled ? 'wss://' : 'ws://') + window.location.host + window.location.pathname + 'ws';";
    if (empty($config['ws_proxy_url']) === true) {
        $new = "var url = (httpsEnabled ? 'wss://' : 'ws://')";
        $new .= " + window.location.host + ':";
        $new .= $config['ws_port'].'/'.$method."';";
    } else {
        $new = "var url = '";
        $new .= $config['ws_proxy_url'].'/'.$method."';";
    }

    // Update firefox issue.
    $original = '    this.iframe_.src = \'#\';';
    $trick = 'this.iframe_.src = \'javascript:\';';

    $r = str_replace($original, $trick, $r);

    // Update url.
    $gotty = str_replace($url, $new, $gotty);

    // Update websocket arguments.
    $args = 'var args = window.location.search;';
    $new = $command_arguments;

    // Update arguments.
    $gotty = str_replace($args, $new, $gotty);

    ?>
    <style>#terminal {
        height: 650px;
        width: 100%;
        margin: 0px;
        padding: 0;
      }
      #terminal > iframe {
        position: relative!important;
      }
    </style>
    <div id="terminal"></div>
    <script type="text/javascript">
    <?php echo $r; ?>
    </script>
    <script type="text/javascript">
    <?php echo $gotty; ?>
    </script>
    <?php

}


/**
 * Provide an interface where configure all settings.
 *
 * @return void
 */
function quickShellSettings()
{
    global $config;

    ui_require_css_file('wizard');
    ui_require_css_file('discovery');

    // Gotty settings. Internal communication (WS).
    if (isset($config['gotty_host']) === false) {
        config_update_value('gotty_host', '127.0.0.1');
    }

    if (isset($config['gotty_telnet_port']) === false) {
        config_update_value('gotty_telnet_port', 8082);
    }

    if (isset($config['gotty_ssh_port']) === false) {
        config_update_value('gotty_ssh_port', 8081);
    }

    // Parser.
    if (get_parameter('update_config', false) !== false) {
        // Gotty settings. Internal communication (WS).
        $gotty = get_parameter(
            'gotty',
            ''
        );
        $gotty_host = get_parameter(
            'gotty_host',
            ''
        );
        $gotty_ssh_port = get_parameter(
            'gotty_ssh_port',
            ''
        );
        $gotty_telnet_port = get_parameter(
            'gotty_telnet_port',
            ''
        );

        $gotty_user = get_parameter(
            'gotty_user',
            ''
        );

        $gotty_pass = get_parameter(
            'gotty_pass',
            ''
        );

        $gotty_pass = io_input_password($gotty_pass);

        $changes = 0;
        $critical = 0;
        if ($config['gotty'] != $gotty) {
            config_update_value('gotty', $gotty);
            $changes++;
            $critical++;
        }

        if ($config['gotty_host'] != $gotty_host) {
            config_update_value('gotty_host', $gotty_host);
            $changes++;
        }

        if ($config['gotty_telnet_port'] != $gotty_telnet_port) {
            config_update_value('gotty_telnet_port', $gotty_telnet_port);
            $changes++;
        }

        if ($config['gotty_ssh_port'] != $gotty_ssh_port) {
            config_update_value('gotty_ssh_port', $gotty_ssh_port);
            $changes++;
        }

        if ($config['gotty_user'] != $gotty_user) {
            config_update_value('gotty_user', $gotty_user);
            $changes++;
            $critical++;
        }

        if ($config['gotty_pass'] != $gotty_pass) {
            $gotty_pass = io_input_password($gotty_pass);
            config_update_value('gotty_pass', $gotty_pass);
            $changes++;
            $critical++;
        }
    }

    if ($changes > 0) {
        $msg = __('%d Updated', $changes);
        if ($critical > 0) {
            $msg = __(
                '%d Updated, please restart WebSocket engine service',
                $changes
            );
        }

        ui_print_success_message($msg);
    }

    // Form. Using old style.
    echo '<fieldset class="margin-bottom-10">';
    echo '<legend>'.__('Quickshell').'</legend>';

    $t = new StdClass();
    $t->data = [];
    $t->width = '100%';
    $t->class = 'filter-table-adv';
    $t->data = [];
    $t->style = [];
    $t->style[0] = 'width: 50%;';

    $t->data[0][] = html_print_label_input_block(
        __('Gotty path'),
        html_print_input_text(
            'gotty',
            $config['gotty'],
            '',
            30,
            100,
            true
        )
    );

    $t->data[0][] = html_print_label_input_block(
        __('Gotty host'),
        html_print_input_text(
            'gotty_host',
            $config['gotty_host'],
            '',
            30,
            100,
            true
        )
    );

    $t->data[1][] = html_print_label_input_block(
        __('Gotty ssh port'),
        html_print_input_text(
            'gotty_ssh_port',
            $config['gotty_ssh_port'],
            '',
            30,
            100,
            true
        )
    );

    $t->data[1][] = html_print_label_input_block(
        __('Gotty telnet port'),
        html_print_input_text(
            'gotty_telnet_port',
            $config['gotty_telnet_port'],
            '',
            30,
            100,
            true
        )
    );

    $hidden = new stdClass();
    $hidden->data = [];
    $hidden->width = '100%';
    $hidden->class = 'filter-table-adv';
    $hidden->data = [];
    $hidden->style[0] = 'width: 50%;';

    $hidden->data[0][] = html_print_label_input_block(
        __('Gotty user'),
        html_print_input_text(
            'gotty_user',
            $config['gotty_user'],
            '',
            30,
            100,
            true
        )
    );

    $hidden->data[0][] = html_print_label_input_block(
        __('Gotty password'),
        html_print_input_password(
            'gotty_pass',
            io_output_password($config['gotty_pass']),
            '',
            30,
            100,
            true
        )
    );

    html_print_table($t);

    ui_print_toggle(
        [
            'content'         => html_print_table($hidden, true),
            'name'            => __('Advanced options'),
            'clean'           => false,
            'main_class'      => 'no-border-imp',
            'container_class' => 'no-border-imp',
        ]
    );

    echo '</fieldset>';

}


// This extension is usefull only if the agent has associated IP.
$agent_id = get_parameter('id_agente');
if (empty($agent_id) === false
    && get_parameter('sec2', '') == 'operation/agentes/ver_agente'
) {
    $address = agents_get_address($agent_id);
    if (empty($address) === false) {
        // Extension registration.
        global $pandora_version;
        if ($pandora_version !== 'v7.0NG.772.1' && $pandora_version !== 'v7.0NG.772.2') {
            extensions_add_opemode_tab_agent(
                // TabId.
                'quick_shell',
                // TabName.
                __('QuickShell'),
                // TabIcon.
                'images/quick-shell@svg.svg',
                // TabFunction.
                'quickShell',
                // Version.
                'N/A',
                // Acl.
                'PM'
            );
        }
    }
}

extensions_add_godmode_function('quickShellSettings');
