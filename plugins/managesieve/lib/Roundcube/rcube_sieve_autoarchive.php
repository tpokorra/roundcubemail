<?php

/**
 * Managesieve Autoarchive Engine
 *
 * Engine part of Managesieve plugin implementing UI and backend access.
 *
 * Copyright (C) Kolab Systems AG
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see http://www.gnu.org/licenses/.
 */

class rcube_sieve_autoarchive extends rcube_sieve_engine
{
    protected $error;
    protected $script_name;
    protected $rulename = "autoarchive";
    protected $autoarchive = array();

    function actions()
    {
        $error = $this->start('autoarchive');

        // find current autoarchive rule
        if (!$error) {
            $this->autoarchive_rule();
            $this->autoarchive_post();
        }

        $this->plugin->add_label('autoarchive.saving');
        $this->rc->output->add_handlers(array(
            'autoarchiveform' => array($this, 'autoarchive_form'),
        ));

        $this->rc->output->set_pagetitle($this->plugin->gettext('autoarchive'));
        $this->rc->output->send('managesieve.autoarchive');
    }

    /**
     * Find and load sieve script with/for autoarchive rule
     *
     * @param string $script_name Optional script name
     *
     * @return int Connection status: 0 on success, >0 on failure
     */
    protected function load_script($script_name = null)
    {
        if ($this->script_name !== null) {
            return 0;
        }

        $list     = $this->list_scripts();
        $master   = $this->rc->config->get('managesieve_kolab_master');
        $included = array();

        $this->script_name = false;

ini_set("log_errors", true);
ini_set("error_log", "/var/log/php_error.log");

        // first try the active script(s)...
        if (!empty($this->active)) {
            // Note: there can be more than one active script on KEP:14-enabled server
            foreach ($this->active as $script) {
                if ($this->sieve->load($script)) {
                    foreach ($this->sieve->script->as_array() as $rule) {
                        if ($rule['name'] == $this->rulename) {
                            $this->script_name = $script;
                            return 0;
                        }
error_log(print_r($rule,true));
                    }
                }
            }
        }

        // try all other scripts
        if (!empty($list)) {
            // else try included scripts
            foreach (array_diff($list, $included, $this->active) as $script) {
                if ($this->sieve->load($script)) {
                    foreach ($this->sieve->script->as_array() as $rule) {
                        if ($rule['name'] == $this->rulename) {
                            $this->script_name = $script;
                            return 0;
                        }
                    }
                }
            }

            // none of the scripts contains existing autoarchive rule
            // use any (first) active or just existing script (in that order)
            if (!empty($this->active)) {
                $this->sieve->load($this->script_name = $this->active[0]);
            }
            else {
                $this->sieve->load($this->script_name = $list[0]);
            }
        }

        return $this->sieve->error();
    }

    private function autoarchive_rule()
    {
        if ($this->script_name === false || $this->script_name === null || !$this->sieve->load($this->script_name)) {
            return;
        }

        $list   = array();
        $active = in_array($this->script_name, $this->active);

        // find the autoarchive rule
        foreach ($this->script as $idx => $rule) {
            if ($rule['name'] == $this->rulename) {
                foreach ($rule['actions'] as $act) {
                    if ($act['type'] == 'fileinto') {
                        $action = $act['type'];
                        $target = $act['target'];
                    }
                }

                $this->autoarchive = array_merge($rule['actions'][0], array(
                        'idx'      => $idx,
                        'disabled' => $rule['disabled'] || !$active,
                        'name'     => $rule['name'],
                        'tests'    => $rule['tests'],
                        'action'   => $action ?: 'keep',
                        'target'   => $target,
                    ));
            }
            else if ($active) {
                $list[$idx] = $rule['name'];
            }
        }

        $this->autoarchive['list'] = $list;
    }

    private function autoarchive_post()
    {
        if (empty($_POST)) {
            return;
        }

        $status = rcube_utils::get_input_value('autoarchive_status', rcube_utils::INPUT_POST);
        //$action = rcube_utils::get_input_value('autoarchive_action', rcube_utils::INPUT_POST);
        //$target = rcube_utils::get_input_value('action_target', rcube_utils::INPUT_POST, true);
        $action = 'fileinto';
        $target = 'Archive/Archive';

        $date_extension = in_array('date', $this->exts);
        $autoarchive_tests  = (array) $this->autoarchive['tests'];

        if (empty($autoarchive_tests)) {
            $autoarchive_tests = (array) $this->rc->config->get('managesieve_autoarchive_test',
                array(array('test' => 'header','not'=>'','arg2'=>'ARCHIVING LOG:','arg1'=>'Subject','type'=>'contains')));
        }

        if (!$error) {
            $rule               = $this->autoarchive;
            $rule['type']       = 'if';
            $rule['name']       = $this->rulename;
            $rule['disabled']   = $status == 'off';
            $rule['tests']      = $autoarchive_tests;
            $rule['join']       = $date_extension ? count($autoarchive_tests) > 1 : false;
            $rule['actions']    = array();
            $rule['after']      = $after;

            if ($action && $action != 'keep') {
                $rule['actions'][] = array(
                    'type'   => $action,
                    'copy'   => $action == 'copy',
                    'target' => $target,
                );
            }

            if ($this->save_autoarchive_script($rule)) {
                $this->rc->output->show_message('managesieve.autoarchivesaved', 'confirmation');

                $this->rc->output->send();
            }
        }

        $this->rc->output->show_message($error ?: 'managesieve.saveerror', 'error');
        $this->rc->output->send();
    }

    /**
     * Independent autoarchive form
     */
    public function autoarchive_form($attrib)
    {
        // build FORM tag
        $form_id = $attrib['id'] ?: 'form';
        $out     = $this->rc->output->request_form(array(
            'id'      => $form_id,
            'name'    => $form_id,
            'method'  => 'post',
            'task'    => 'settings',
            'action'  => 'plugin.managesieve-autoarchive',
            'noclose' => true
            ) + $attrib);


        // form elements
        $status = new html_select(array('name' => 'autoarchive_status', 'id' => 'autoarchive_status'));

        $status->add($this->plugin->gettext('autoarchive.on'), 'on');
        $status->add($this->plugin->gettext('autoarchive.off'), 'off');

        // Message tab
        $table = new html_table(array('cols' => 2));

        $table->add('title', html::label('autoarchive_status', $this->plugin->gettext('autoarchive.status')));
        $table->add(null, $status->show(!isset($this->autoarchive['disabled']) || $this->autoarchive['disabled'] ? 'off' : 'on'));

        $out .= $table->show($attrib);

        $out .= '</form>';

        $this->rc->output->add_gui_object('sieveform', $form_id);

        return $out;
    }

    /**
     * Saves autoarchive script (adding some variables)
     */
    protected function save_autoarchive_script($rule)
    {
        // if script does not exist create a new one
        if ($this->script_name === null || $this->script_name === false) {
            $this->script_name = $this->rc->config->get('managesieve_script_name');
            if (empty($this->script_name)) {
                $this->script_name = 'roundcube';
            }

            // use default script contents
            if (!$this->rc->config->get('managesieve_kolab_master')) {
                $script_file = $this->rc->config->get('managesieve_default');
                if ($script_file && is_readable($script_file)) {
                    $content = file_get_contents($script_file);
                }
            }

            // create and load script
            if ($this->sieve->save_script($this->script_name, $content)) {
                $this->sieve->load($this->script_name);
            }
        }

        $script_active = in_array($this->script_name, $this->active);

        // re-order rules if needed
        if (isset($rule['after']) && $rule['after'] !== '') {
            // reset original autoarchive rule
            if (isset($this->autoarchive['idx'])) {
                $this->script[$this->autoarchive['idx']] = null;
            }

            // add at target position
            if ($rule['after'] >= count($this->script) - 1) {
                $this->script[] = $rule;
            }
            else {
                $script = array();

                foreach ($this->script as $idx => $r) {
                    if ($r) {
                        $script[] = $r;
                    }

                    if ($idx == $rule['after']) {
                        $script[] = $rule;
                    }
                }

                $this->script = $script;
            }

            $this->script = array_values(array_filter($this->script));
        }
        // update original autoarchive rule if it exists
        else if (isset($this->autoarchive['idx'])) {
            $this->script[$this->autoarchive['idx']] = $rule;
        }
        // otherwise put autoarchive rule on top
        else {
            array_unshift($this->script, $rule);
        }

        // if the script was not active, we need to de-activate
        // all rules except the autoarchive rule, but only if it is not disabled
        if (!$script_active && !$rule['disabled']) {
            foreach ($this->script as $idx => $r) {
                if (empty($r['actions']) || $r['actions'][0]['type'] != 'autoarchive') {
                    $this->script[$idx]['disabled'] = true;
                }
            }
        }

        if (!$this->sieve->script) {
            return false;
        }

        $this->sieve->script->content = $this->script;

        // save the script
        $saved = $this->save_script($this->script_name);

        // activate the script
        if ($saved && !$script_active && !$rule['disabled']) {
            $this->activate_script($this->script_name);
        }

        return $saved;
    }

    /**
     * API: get autoarchive rule
     *
     * @return array autoarchive rule information
     */
    public function get_autoarchive()
    {
        $this->exts = $this->sieve->get_extensions();
        $this->init_script();
        $this->autoarchive_rule();

        $autoarchive = array(
            'supported' => $this->exts,
            'enabled'   => empty($this->autoarchive['disabled']),
            'action'    => $this->autoarchive['action'],
            'target'    => $this->autoarchive['target'],
        );

        return $autoarchive;
    }

    /**
     * API: set autoarchive rule
     *
     * @param array $autoarchive autoarchive rule information (see self::get_autoarchive())
     *
     * @return bool True on success, False on failure
     */
    public function set_autoarchive($data)
    {
        $this->exts  = $this->sieve->get_extensions();
        $this->error = false;

        $this->init_script();
        $this->autoarchive_rule();

        $date_extension = in_array('date', $this->exts);

        $data['action'] = 'autoarchive';

        $autoarchive_tests = (array) $this->rc->config->get('managesieve_autoarchive_test',
            array(array('test' => 'header :contains "Subject" "ARCHIVING LOG:"')));

        $rule             = $this->autoarchive;
        $rule['type']     = 'if';
        $rule['name']     = 'FileAutoArchiveLog';
        $rule['disabled'] = isset($data['enabled']) && !$data['enabled'];
        $rule['tests']    = $autoarchive_tests;
        $rule['join']     = $date_extension ? count($autoarchive_tests) > 1 : false;
        $rule['actions']  = array();

        if ($data['action'] && $data['action'] != 'keep') {
            $rule['actions'][] = array(
                'type'   => 'fileinto',
                'copy'   => false,
                'target' => 'Archive/Archive',
            );
        }

        return $this->save_autoarchive_script($rule);
    }

    /**
     * API: connect to managesieve server
     */
    public function connect($username, $password)
    {
        $error = parent::connect($username, $password);

        if ($error) {
            return $error;
        }

        return $this->load_script();
    }

    /**
     * API: Returns last error
     *
     * @return string Error message
     */
    public function get_error()
    {
        return $this->error;
    }
}
