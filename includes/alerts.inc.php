<?php

/**
 * Observium Network Management and Monitoring System
 * Copyright (C) 2006-2015, Adam Armstrong - http://www.observium.org
 *
 * @package    observium
 * @subpackage alerter
 * @author     Adam Armstrong <adama@observium.org>
 * @copyright  (C) 2006-2013 Adam Armstrong, (C) 2013-2016 Observium Limited
 *
 */

// DOCME needs phpdoc block
// TESTME needs unit testing
function get_contact_by_id($contact_id)
{
    if (is_numeric($contact_id)) {
        $contact = dbFetchRow('SELECT * FROM `alert_contacts` WHERE `contact_id` = ?', array($contact_id));
    }
    if (is_array($contact) && count($contact)) {
        return $contact;
    } else {
        return FALSE;
    }
}

// DOCME needs phpdoc block
// TESTME needs unit testing
function get_alert_test_by_id($alert_test_id)
{
    if (is_numeric($alert_test_id)) {
        $alert_test = dbFetchRow('SELECT * FROM `alert_tests` WHERE `alert_test_id` = ?', array($alert_test_id));
    }
    if (is_array($alert_test) && count($alert_test)) {
        return $alert_test;
    } else {
        return FALSE;
    }
}

/**
 * Check an entity against all relevant alerts
 *
 * @param string type
 * @param array entity
 * @param array data
 * @return NULL
 */
// TESTME needs unit testing
function check_entity($entity_type, $entity, $data)
{
    global $config, $alert_rules, $alert_table, $device;

    $alert_output = "";

    $entity_data = entity_type_translate_array($entity_type);
    $entity_id_field     = $entity_data['id_field'];
    $entity_ignore_field = $entity_data['ignore_field'];

    $entity_id = $entity[$entity_id_field];

    if (!isset($alert_table[$entity_type][$entity_id])) {
        return;
    } // Just return to avoid PHP warnings

    $alert_info = array('entity_type' => $entity_type, 'entity_id' => $entity_id);

    foreach ($alert_table[$entity_type][$entity_id] as $alert_test_id => $alert_args) {
        if ($alert_rules[$alert_test_id]['and']) {
            $alert = TRUE;
        } else {
            $alert = FALSE;
        }

        $alert_info['alert_test_id'] = $alert_test_id;

        $alert_checker = $alert_rules[$alert_test_id];

        $update_array = array();

        if (is_array($alert_rules[$alert_test_id])) {
            //echo("Checking alert ".$alert_test_id." associated by ".$alert_args['alert_assocs']."\n");
            $alert_output .= $alert_rules[$alert_test_id]['alert_name'] . " [";

            foreach ($alert_rules[$alert_test_id]['conditions'] as $test_key => $test) {
                if (substr($test['value'], 0, 1) == "@") {
                    $ent_val = substr($test['value'], 1);
                    $test['value'] = $entity[$ent_val];
                    //echo(" replaced @".$ent_val." with ". $test['value'] ." from entity. ");
                }

                //echo("Testing: " . $test['metric']. " ". $test['condition'] . " " .$test['value']);
                $update_array['state']['metrics'][$test['metric']] = $data[$test['metric']];

                if (isset($data[$test['metric']])) {
                    //echo(" (value: ".$data[$test['metric']].")");
                    if (test_condition($data[$test['metric']], $test['condition'], $test['value'])) {
                        // A test has failed. Set the alert variable and make a note of what failed.
                        //print_cli("%R[FAIL]%N");
                        $update_array['state']['failed'][] = $test;

                        if ($alert_rules[$alert_test_id]['and']) {
                            $alert = ($alert && TRUE);
                        } else {
                            $alert = ($alert || TRUE);
                        }
                    } else {
                        if ($alert_rules[$alert_test_id]['and']) {
                            $alert = ($alert && FALSE);
                        } else {
                            $alert = ($alert || FALSE);
                        }
                        //print_cli("%G[OK]%N");
                    }
                } else {
                    //echo("  Metric is not present on entity.\n");
                    if ($alert_rules[$alert_test_id]['and']) {
                        $alert = ($alert && FALSE);
                    } else {
                        $alert = ($alert || FALSE);
                    }
                }
            }

            if ($alert) {
                // Check to see if this alert has been suppressed by anything
                ## FIXME -- not all of this is implemented

                // Have all alerts been suppressed?
                if ($config['alerts']['suppress']) {
                    $alert_suppressed = TRUE;
                    $suppressed[] = "GLOBAL";
                }

                // Is there a global scheduled maintenance?
                if (isset($GLOBALS['cache']['maint']['global']) && count($GLOBALS['cache']['maint']['global']) > 0) {
                    $alert_suppressed = TRUE;
                    $suppressed[] = "MNT_GBL";
                }

                // Have all alerts on the device been suppressed?
                if ($device['ignore']) {
                    $alert_suppressed = TRUE;
                    $suppressed[] = "DEV";
                }
                if ($device['ignore_until']) {
                    $device['ignore_until_time'] = strtotime($device['ignore_until']);
                    if ($device['ignore_until_time'] > time()) {
                        $alert_suppressed = TRUE;
                        $suppressed[] = "DEV_U";
                    }
                }

                if (isset($GLOBALS['cache']['maint'][$entity_type][$entity[$entity_id_field]])) {
                    $alert_suppressed = TRUE;
                    $suppressed[] = "MNT_ENT";
                }

                if (isset($GLOBALS['cache']['maint']['alert_checker'][$alert_test_id])) {
                    $alert_suppressed = TRUE;
                    $suppressed[] = "MNT_CHK";
                }

                if (isset($GLOBALS['cache']['maint']['device'][$device['device_id']])) {
                    $alert_suppressed = TRUE;
                    $suppressed[] = "MNT_DEV";
                }

                // Have all alerts on the entity been suppressed?
                if ($entity[$entity_ignore_field]) {
                    $alert_suppressed = TRUE;
                    $suppressed[] = "ENT";
                }
                if (is_numeric($entity['ignore_until']) && $entity['ignore_until'] > time()) {
                    $alert_suppressed = TRUE;
                    $suppressed[] = "ENT_U";
                }

                // Have alerts from this alerter been suppressed?
                if ($alert_rules[$alert_test_id]['ignore']) {
                    $alert_suppressed = TRUE;
                    $suppressed[] = "CHECK";
                }
                if ($alert_rules[$alert_test_id]['ignore_until']) {
                    $alert_rules[$alert_test_id]['ignore_until_time'] = strtotime($alert_rules[$alert_test_id]['ignore_until']);
                    if ($alert_rules[$alert_test_id]['ignore_until_time'] > time()) {
                        $alert_suppressed = TRUE;
                        $suppressed[] = "CHECK_UNTIL";
                    }
                }

                // Has this specific alert been suppressed?
                if ($alert_args['ignore']) {
                    $alert_suppressed = TRUE;
                    $suppressed[] = "ENTRY";
                }
                if ($alert_args['ignore_until']) {
                    $alert_args['ignore_until_time'] = strtotime($alert_args['ignore_until']);
                    if ($alert_args['ignore_until_time'] > time()) {
                        $alert_suppressed = TRUE;
                        $suppressed[] = "ENTRY_UNTIL";
                    }
                }

                if (is_numeric($alert_args['ignore_until_ok']) && $alert_args['ignore_until_ok'] == '1') {
                    $alert_suppressed = TRUE;
                    $suppressed[] = "ENTRY_UNTIL_OK";
                }

                $update_array['count'] = $alert_args['count'] + 1;

                // Check against the alert test's delay
                if ($alert_args['count'] >= $alert_rules[$alert_test_id]['delay'] && $alert_suppressed) {
                    // This alert is valid, but has been suppressed.
                    //echo(" Checks failed. Alert suppressed (".implode(', ', $suppressed).").\n");
                    $alert_output .= "%PFS%N";
                    $update_array['alert_status'] = '3';
                    $update_array['last_message'] = 'Checks failed (Suppressed: ' . implode(', ', $suppressed) . ')';
                    $update_array['last_checked'] = time();
                    if ($alert_args['alert_status'] != '3' || $alert_args['last_changed'] == '0') {
                        $update_array['last_changed'] = time();
                        $log_id = log_alert('Checks failed but alert suppressed by [' . implode($suppressed, ',') . ']', $device, $alert_info, 'FAIL_SUPPRESSED');
                    }
                    $update_array['last_failed'] = time();
                } elseif ($alert_args['count'] >= $alert_rules[$alert_test_id]['delay']) {
                    // This is a real alert.
                    //echo(" Checks failed. Generate alert.\n");
                    $alert_output .= "%PF!%N";
                    $update_array['alert_status'] = '0';
                    $update_array['last_message'] = 'Checks failed';
                    $update_array['last_checked'] = time();
                    if ($alert_args['alert_status'] != '0' || $alert_args['last_changed'] == '0') {
                        $update_array['last_changed'] = time();
                        $update_array['last_alerted'] = '0';
                        $log_id = log_alert('Checks failed', $device, $alert_info, 'FAIL');
                    }
                    $update_array['last_failed'] = time();
                } else {
                    // This is alert needs to exist for longer.
                    //echo(" Checks failed. Delaying alert.\n");
                    $alert_output .= "%OFD%N";
                    $update_array['alert_status'] = '2';
                    $update_array['last_message'] = 'Checks failed (delayed)';
                    $update_array['last_checked'] = time();
                    if ($alert_args['alert_status'] != '2' || $alert_args['last_changed'] == '0') {
                        $update_array['last_changed'] = time();
                        $log_id = log_alert('Checks failed but alert delayed', $device, $alert_info, 'FAIL_DELAYED');
                    }
                    $update_array['last_failed'] = time();
                }
            } else {
                $update_array['count'] = 0;
                // Alert conditions passed. Record that we tested it and update status and other data.
                $alert_output .= "%gOK%N";
                $update_array['alert_status'] = '1';
                $update_array['last_message'] = 'Checks OK';
                $update_array['last_checked'] = time();
                #$update_array['count'] = 0;
                if ($alert_args['alert_status'] != '1' || $alert_args['last_changed'] == '0') {
                    $update_array['last_changed'] = time();
                    $log_id = log_alert('Checks succeeded', $device, $alert_info, 'OK');
                }
                $update_array['last_ok'] = time();

                // Status is OK, so disable ignore_until_ok if it has been enabled
                if ($alert_args['ignore_until_ok'] != '0') {
                    $update_entry_array['ignore_until_ok'] = '0';
                }
            }

            unset($suppressed);
            unset($alert_suppressed);

            // json_encode the state array before we put it into MySQL.
            $update_array['state'] = json_encode($update_array['state']);
            #$update_array['alert_table_id'] = $alert_args['alert_table_id'];

            /// Perhaps this is better done with SQL replace?
            #print_vars($alert_args);
            //if (!$alert_args['state_entry'])
            //{
            // State entry seems to be missing. Insert it before we update it.
            //dbInsert(array('alert_table_id' => $alert_args['alert_table_id']), 'alert_table-state');
            // echo("I+");
            //}
            dbUpdate($update_array, 'alert_table', '`alert_table_id` = ?', array($alert_args['alert_table_id']));
            if (is_array($update_entry_array)) {
                dbUpdate($update_entry_array, 'alert_table', '`alert_table_id` = ?', array($alert_args['alert_table_id']));
            }

            if (TRUE) {
                // Write RRD data
                if ($update_array['alert_status'] == "1") {
                    // Status is up
                    rrdtool_update_ng($device, 'alert', array('status' => 1, 'code' => $update_array['alert_status']), $alert_args['alert_table_id']);
                } else {
                    rrdtool_update_ng($device, 'alert', array('status' => 0, 'code' => $update_array['alert_status']), $alert_args['alert_table_id']);
                }
            }
        } else {
            $alert_output .= "%RAlert missing!%N";
        }

        $alert_output .= ("] ");
    }

    $alert_output .= "%n";

    if ($entity_type == "device") {
        $cli_level = 1;
    } else {
        $cli_level = 3;
    }

    //print_cli_data("Checked Alerts", $alert_output, $cli_level);
}

/**
 * Build an array of conditions that apply to a supplied device
 *
 * This takes the array of global conditions and removes associations that don't match the supplied device array
 *
 * @param  array device
 * @return array
 */
// TESTME needs unit testing
function cache_device_conditions($device)
{
    // Return no conditions if the device is ignored or disabled.

    if ($device['ignore'] == 1 || $device['disabled'] == 1) {
        return array();
    }

    $conditions = cache_conditions();

    foreach ($conditions['assoc'] as $assoc_key => $assoc) {
        if (match_device($device, $assoc['device_attribs'])) {
            $assoc['alert_test_id'];
            $conditions['cond'][$assoc['alert_test_id']]['assoc'][$assoc_key] = $conditions['assoc'][$assoc_key];
            $cond_new['cond'][$assoc['alert_test_id']] = $conditions['cond'][$assoc['alert_test_id']];
        } else {
            unset($conditions['assoc'][$assoc_key]);
        }
    }

    return $cond_new;
}

/**
 * Fetch array of alerts to a supplied device from `alert_table`
 *
 * This takes device_id as argument and returns an array.
 *
 * @param device_id
 * @return array
 */
// TESTME needs unit testing
function cache_device_alert_table($device_id)
{
    $alert_table = array();

    $sql = "SELECT * FROM  `alert_table`";
    //$sql .= " LEFT JOIN `alert_table-state` USING(`alert_table_id`)";
    $sql .= " WHERE `device_id` = ?";

    foreach (dbFetchRows($sql, array($device_id)) as $entry) {
        $alert_table[$entry['entity_type']][$entry['entity_id']][$entry['alert_test_id']] = $entry;
    }

    return $alert_table;
}

/**
 * Build an array of all alert rules
 *
 * @return array
 */
// TESTME needs unit testing
function cache_alert_rules($vars = array())
{
    $alert_rules = array();
    $rules_count = 0;
    $where = 'WHERE 1';
    $args = array();

    if (isset($vars['entity_type']) && $vars['entity_type'] !== "all") {
        $where .= ' AND `entity_type` = ?';
        $args[] = $vars['entity_type'];
    }

    foreach (dbFetchRows("SELECT * FROM `alert_tests` " . $where, $args) as $entry) {
        if ($entry['alerter'] == '') {
            $entry['alerter'] = "default";
        }
        $alert_rules[$entry['alert_test_id']] = $entry;
        $alert_rules[$entry['alert_test_id']]['conditions'] = json_decode($entry['conditions'], TRUE);
        $rules_count++;
    }

    print_debug("Cached $rules_count alert rules.");

    return $alert_rules;
}

// FIXME. Never used, deprecated?
// DOCME needs phpdoc block
// TESTME needs unit testing
function generate_alerter_info($alerter)
{
    global $config;

    if (is_array($config['alerts']['alerter'][$alerter])) {
        $a = $config['alerts']['alerter'][$alerter];
        $output = "<strong>" . $a['descr'] . "</strong><hr />";
        $output .= $a['type'] . ": " . $a['contact'] . "<br />";
        if ($a['enable']) {
            $output .= "Enabled";
        } else {
            $output .= "Disabled";
        }
        return $output;
    } else {
        return "Unknown alerter.";
    }
}

// DOCME needs phpdoc block
// TESTME needs unit testing
function cache_alert_assoc()
{
    $alert_assoc = array();

    foreach (dbFetchRows("SELECT * FROM `alert_assoc`") as $entry) {
        $entity_attribs = json_decode($entry['entity_attribs'], TRUE);
        $device_attribs = json_decode($entry['device_attribs'], TRUE);
        $alert_assoc[$entry['alert_assoc_id']]['entity_type'] = $entry['entity_type'];
        $alert_assoc[$entry['alert_assoc_id']]['entity_attribs'] = $entity_attribs;
        $alert_assoc[$entry['alert_assoc_id']]['device_attribs'] = $device_attribs;
        $alert_assoc[$entry['alert_assoc_id']]['alert_test_id'] = $entry['alert_test_id'];
    }

    return $alert_assoc;
}

/**
 * Build an array of scheduled maintenances
 *
 * @return array
 *
 */
// TESTME needs unit testing
function cache_alert_maintenance()
{

    $return = array();
    $now = time();

    $maints = dbFetchRows("SELECT * FROM `alerts_maint` WHERE `maint_start` < ? AND `maint_end` > ?", array($now, $now));

    if (is_array($maints) && count($maints)) {

        $return['count'] = count($maints);

        foreach ($maints as $maint) {
            if ($maint['maint_global'] == 1) {
                $return['global'][$maint['maint_id']] = $maint;
            } else {

                $assocs = dbFetchRows("SELECT * FROM `alerts_maint_assoc` WHERE `maint_id` = ?", array($maint['maint_id']));

                foreach ($assocs as $assoc) {
                    switch ($assoc['entity_type']) {
                        case "group": // this is a group, so expand it's members into an array
                            $group = get_group_by_id($assoc['entity_id']);
                            $entities = get_group_entities($assoc['entity_id']);
                            foreach ($entities as $entity) {
                                $return[$group['entity_type']][$entity] = TRUE;
                            }
                            break;
                        default:
                            $return[$assoc['entity_type']][$assoc['entity_id']] = TRUE;
                            break;
                    }
                }

            }
        }
    }

    //print_r($return);

    return $return;

}

function get_maintenance_associations($maint_id = NULL)
{
    $return = array();

#  if ($maint_id)
#  {
    $assocs = dbFetchRows("SELECT * FROM `alerts_maint_assoc` WHERE `maint_id` = ?", array($maint_id));
#  } else {
#    $assocs = dbFetchRows("SELECT * FROM `alerts_maint_assoc`");
#  }

    foreach ($assocs as $assoc) {
        $return[$assoc['entity_type']][$assoc['entity_id']] = TRUE;
    }

    return $return;

}

/**
 * Build an array of all conditions
 *
 * @return array
 */
// TESTME needs unit testing
function cache_conditions()
{
    $cache = array();

    foreach (dbFetchRows("SELECT * FROM `alert_tests`") as $entry) {
        $cache['cond'][$entry['alert_test_id']] = $entry;
        $conditions = json_decode($entry['conditions'], TRUE);
        $cache['cond'][$entry['alert_test_id']]['entity_type'] = $entry['entity_type'];
        $cache['cond'][$entry['alert_test_id']]['conditions'] = $conditions;
    }

    foreach (dbFetchRows("SELECT * FROM `alert_assoc`") as $entry) {
        $entity_attribs = json_decode($entry['entity_attribs'], TRUE);
        $device_attribs = json_decode($entry['device_attribs'], TRUE);
        $cache['assoc'][$entry['alert_assoc_id']] = $entry;
        $cache['assoc'][$entry['alert_assoc_id']]['entity_attribs'] = $entity_attribs;
        $cache['assoc'][$entry['alert_assoc_id']]['device_attribs'] = $device_attribs;
    }

    return $cache;
}

/**
 * Compare two values
 *
 * @param string $value_a
 * @param string $condition
 * @param string $value_b
 * @return boolean
 */
// TESTME needs unit testing
function test_condition($value_a, $condition, $value_b)
{
    $value_a = trim($value_a);
    if (!is_array($value_b)) {
        $value_b = trim($value_b);
    }
    $condition = strtolower($condition);
    $delimiters = array('/', '!', '@');

    switch ($condition) {
        case 'ge':
        case '>=':
            if ($value_a >= unit_string_to_numeric($value_b)) {
                $alert = TRUE;
            } else {
                $alert = FALSE;
            }
            break;
        case 'le':
        case '<=':
            if ($value_a <= unit_string_to_numeric($value_b)) {
                $alert = TRUE;
            } else {
                $alert = FALSE;
            }
            break;
        case 'gt':
        case 'greater':
        case '>':
            if ($value_a > unit_string_to_numeric($value_b)) {
                $alert = TRUE;
            } else {
                $alert = FALSE;
            }
            break;
        case 'lt':
        case 'less':
        case '<':
            if ($value_a < unit_string_to_numeric($value_b)) {
                $alert = TRUE;
            } else {
                $alert = FALSE;
            }
            break;
        case 'notequals':
        case 'isnot':
        case 'ne':
        case '!=':
            if ($value_a != unit_string_to_numeric($value_b)) {
                $alert = TRUE;
            } else {
                $alert = FALSE;
            }
            break;
        case 'equals':
        case 'eq':
        case 'is':
        case '==':
        case '=':
            if ($value_a == unit_string_to_numeric($value_b)) {
                $alert = TRUE;
            } else {
                $alert = FALSE;
            }
            break;
        case 'match':
        case 'matches':
            $value_b = str_replace('*', '.*', $value_b);
            $value_b = str_replace('?', '.', $value_b);

            foreach ($delimiters as $delimiter) {
                if (!str_contains($value_b, $delimiter)) {
                    break;
                }
            }
            if (preg_match($delimiter . '^' . $value_b . '$' . $delimiter, $value_a)) {
                $alert = TRUE;
            } else {
                $alert = FALSE;
            }
            break;
        case 'notmatches':
        case 'notmatch':
        case '!match':
            $value_b = str_replace('*', '.*', $value_b);
            $value_b = str_replace('?', '.', $value_b);

            foreach ($delimiters as $delimiter) {
                if (!str_contains($value_b, $delimiter)) {
                    break;
                }
            }
            if (preg_match($delimiter . '^' . $value_b . '$' . $delimiter, $value_a)) {
                $alert = FALSE;
            } else {
                $alert = TRUE;
            }
            break;
        case 'regexp':
        case 'regex':
            foreach ($delimiters as $delimiter) {
                if (!str_contains($value_b, $delimiter)) {
                    break;
                }
            }
            if (preg_match($delimiter . $value_b . $delimiter, $value_a)) {
                $alert = TRUE;
            } else {
                $alert = FALSE;
            }
            break;
        case 'notregexp':
        case 'notregex':
        case '!regexp':
        case '!regex':
            foreach ($delimiters as $delimiter) {
                if (!str_contains($value_b, $delimiter)) {
                    break;
                }
            }
            if (preg_match($delimiter . $value_b . $delimiter, $value_a)) {
                $alert = FALSE;
            } else {
                $alert = TRUE;
            }
            break;
        case 'in':
        case 'list':
            if (!is_array($value_b)) {
                $value_b = explode(',', $value_b);
            }
            $alert = in_array($value_a, $value_b);
            break;
        case '!in':
        case '!list':
        case 'notin':
        case 'notlist':
            if (!is_array($value_b)) {
                $value_b = explode(',', $value_b);
            }
            $alert = !in_array($value_a, $value_b);
            break;
        default:
            $alert = FALSE;
            break;
    }

    return $alert;
}

/**
 * Test if a device matches a set of attributes
 * Matches using the database entry for the supplied device_id
 *
 * @param array device
 * @param array attributes
 * @return boolean
 */
// TESTME needs unit testing
function match_device($device, $attributes, $ignore = TRUE)
{
    // Short circuit this check if the device is either disabled or ignored.
    if ($ignore && ($device['disable'] == 1 || $device['ignore'] == 1)) {
        return FALSE;
    }

    $query = "SELECT COUNT(*) FROM `devices` AS d";
    $join = "";
    $where = " WHERE d.`device_id` = ?";
    $params = array($device['device_id']);

    foreach ($attributes as $attrib) {
        switch ($attrib['condition']) {
            case 'ge':
            case '>=':
                $where .= ' AND d.`' . $attrib['attrib'] . '` >= ?';
                $params[] = $attrib['value'];
                break;
            case 'le':
            case '<=':
                $where .= ' AND d.`' . $attrib['attrib'] . '` <= ?';
                $params[] = $attrib['value'];
                break;
            case 'gt':
            case 'greater':
            case '>':
                $where .= ' AND d.`' . $attrib['attrib'] . '` > ?';
                $params[] = $attrib['value'];
                break;
            case 'lt':
            case 'less':
            case '<':
                $where .= ' AND d.`' . $attrib['attrib'] . '` < ?';
                $params[] = $attrib['value'];
                break;
            case 'notequals':
            case 'isnot':
            case 'ne':
            case '!=':
                $where .= ' AND d.`' . $attrib['attrib'] . '` != ?';
                $params[] = $attrib['value'];
                break;
            case 'equals':
            case 'eq':
            case 'is':
            case '==':
            case '=':
                $where .= ' AND d.`' . $attrib['attrib'] . '` = ?';
                $params[] = $attrib['value'];
                break;
            case 'match':
            case 'matches':
                $attrib['value'] = str_replace('*', '%', $attrib['value']);
                $attrib['value'] = str_replace('?', '_', $attrib['value']);
                $where .= ' AND IFNULL(d.`' . $attrib['attrib'] . '`, "") LIKE ?';
                $params[] = $attrib['value'];
                break;
            case 'notmatches':
            case 'notmatch':
            case '!match':
                $attrib['value'] = str_replace('*', '%', $attrib['value']);
                $attrib['value'] = str_replace('?', '_', $attrib['value']);
                $where .= ' AND IFNULL(d.`' . $attrib['attrib'] . '`, "") NOT LIKE ?';
                $params[] = $attrib['value'];
                break;
            case 'regexp':
            case 'regex':
                $where .= ' AND IFNULL(d.`' . $attrib['attrib'] . '`, "") REGEXP ?';
                $params[] = $attrib['value'];
                break;
            case 'notregexp':
            case 'notregex':
            case '!regexp':
            case '!regex':
                $where .= ' AND IFNULL(d.`' . $attrib['attrib'] . '`, "") NOT REGEXP ?';
                $params[] = $attrib['value'];
                break;
            case 'in':
            case 'list':
                $where .= generate_query_values(explode(',', $attrib['value']), 'd.' . $attrib['attrib']);
                break;
            case '!in':
            case '!list':
            case 'notin':
            case 'notlist':
                $where .= generate_query_values(explode(',', $attrib['value']), 'd.' . $attrib['attrib'], '!=');
                break;
            case 'include':
            case 'includes':
                switch ($attrib['attrib']) {
                    case 'group':
                        $join .= " INNER JOIN `group_table` USING(`device_id`)";
                        $join .= " INNER JOIN `groups`      USING(`group_id`)";
                        $where .= " AND `group_name` = ?";
                        $params[] = $attrib['value'];
                        break;
                    case 'group_id':
                        $join .= " INNER JOIN `group_table` USING(`device_id`)";
                        $where .= " AND `group_id` = ?";
                        $params[] = $attrib['value'];
                        break;

                }
                break;
        }
    }

    $query .= $join . $where;
    $device_count = dbFetchCell($query, $params);

    if ($device_count == 0) {
        return FALSE;
    } else {
        return TRUE;
    }
}

/**
 * Return an array of entities of a certain type which match device_id and entity attribute rules.
 *
 * @param integer device_id
 * @param array attributes
 * @param string entity_type
 * @return array
 */
// TESTME needs unit testing
function match_device_entities($device_id, $entity_attribs, $entity_type)
{
    // FIXME - this is going to be horribly slow.

    $e_type = $entity_type;
    $entity_type = entity_type_translate_array($entity_type);

    if (!is_array($entity_type)) {
        return NULL;
    } // Do nothing if entity type unknown

    $param = array();
    $sql = "SELECT * FROM `" . dbEscape($entity_type['table']) . "`"; // FIXME. Not sure why these required escape table name

    if(isset($entity_type['parent_table']) && isset($entity_type['parent_id_field']))
    {
        $sql .= ' LEFT JOIN `'.$entity_type['parent_table'].'` USING (`'.$entity_type['parent_id_field'].'`)';
    }


    $sql .= " WHERE device_id = ?";


    if (isset($entity_type['where'])) {
        $sql .= ' AND ' . $entity_type['where'];
    }

    $param[] = $device_id;

    if (isset($entity_type['deleted_field'])) {
        $sql .= " AND `" . $entity_type['deleted_field'] . "` != ?";
        $param[] = '1';
    }

    foreach ($entity_attribs as $attrib) {
        switch ($attrib['condition']) {
            case 'ge':
            case '>=':
                $sql .= ' AND `' . $attrib['attrib'] . '` >= ?';
                $param[] = $attrib['value'];
                break;
            case 'le':
            case '<=':
                $sql .= ' AND `' . $attrib['attrib'] . '` <= ?';
                $param[] = $attrib['value'];
                break;
            case 'gt':
            case 'greater':
            case '>':
                $sql .= ' AND `' . $attrib['attrib'] . '` > ?';
                $param[] = $attrib['value'];
                break;
            case 'lt':
            case 'less':
            case '<':
                $sql .= ' AND `' . $attrib['attrib'] . '` < ?';
                $param[] = $attrib['value'];
                break;
            case 'notequals':
            case 'isnot':
            case 'ne':
            case '!=':
                $sql .= ' AND `' . $attrib['attrib'] . '` != ?';
                $param[] = $attrib['value'];
                break;
            case 'equals':
            case 'eq':
            case 'is':
            case '==':
            case '=':
                $sql .= ' AND `' . $attrib['attrib'] . '` = ?';
                $param[] = $attrib['value'];
                break;
            case 'match':
            case 'matches':
                $attrib['value'] = str_replace('*', '%', $attrib['value']);
                $attrib['value'] = str_replace('?', '_', $attrib['value']);
                $sql .= ' AND IFNULL(`' . $attrib['attrib'] . '`, "") LIKE ?';
                $param[] = $attrib['value'];
                break;
            case 'notmatches':
            case 'notmatch':
            case '!match':
                $attrib['value'] = str_replace('*', '%', $attrib['value']);
                $attrib['value'] = str_replace('?', '_', $attrib['value']);
                $sql .= ' AND IFNULL(`' . $attrib['attrib'] . '`, "") NOT LIKE ?';
                $param[] = $attrib['value'];
                break;
            case 'regexp':
            case 'regex':
                $sql .= ' AND IFNULL(`' . $attrib['attrib'] . '`, "") REGEXP ?';
                $param[] = $attrib['value'];
                break;
            case 'notregexp':
            case 'notregex':
            case '!regexp':
            case '!regex':
                $sql .= ' AND IFNULL(`' . $attrib['attrib'] . '`, "") NOT REGEXP ?';
                $param[] = $attrib['value'];
                break;
            case 'in':
            case 'list':
                $sql .= generate_query_values(explode(',', $attrib['value']), $attrib['attrib']);
                break;
            case '!in':
            case '!list':
            case 'notin':
            case 'notlist':
                $sql .= generate_query_values(explode(',', $attrib['value']), $attrib['attrib'], '!=');
                break;
            case 'include':
            case 'includes':
                switch ($attrib['attrib']) {
                    case 'group':
                        $group = get_group_by_name($attrib['value']);
                        if ($group['entity_type'] == $e_type) {
                            $attrib['value'] = $group['group_id'];
                        }
                    case 'group_id':
                        $values = get_group_entities($attrib['value']);
                        $sql .= generate_query_values($values, $entity_type['table_fields']['id']);
                        break;
                }
        }
    }

    // print_vars(array($sql, $param));

    $entities = dbFetchRows($sql, $param);

    return $entities;
}

/**
 * Test if an entity matches a set of attributes
 * Uses a supplied device array for matching.
 *
 * @param array entity
 * @param array attributes
 * @return boolean
 */
// TESTME needs unit testing
function match_entity($entity, $entity_attribs)
{
    // FIXME. Never used, deprecated?
    #print_vars($entity);
    #print_vars($entity_attribs);

    $failed = 0;
    $success = 0;
    $delimiters = array('/', '!', '@');

    foreach ($entity_attribs as $attrib) {
        switch ($attrib['condition']) {
            case 'equals':
                if (mb_strtolower($entity[$attrib['attrib']]) == mb_strtolower($attrib['value'])) {
                    $success++;
                } else {
                    $failed++;
                }
                break;
            case 'match':
                $attrib['value'] = str_replace('*', '.*', $attrib['value']);
                $attrib['value'] = str_replace('?', '.', $attrib['value']);

                foreach ($delimiters as $delimiter) {
                    if (!str_contains($attrib['value'], $delimiter)) {
                        break;
                    }
                }
                if (preg_match($delimiter . '^' . $attrib['value'] . '$' . $delimiter . 'i', $entity[$attrib['attrib']])) {
                    $success++;
                } else {
                    $failed++;
                }
                break;
        }
    }

    if ($failed) {
        return FALSE;
    } else {
        return TRUE;
    }
}

// DOCME needs phpdoc block
// TESTME needs unit testing
function update_device_alert_table($device)
{
    $dbc = array();
    $alert_table = array();

    $msg = "<h4>Building alerts for device " . $device['hostname'] . ':</h4>';
    $msg_class = '';
    $msg_enable = FALSE;
    $conditions = cache_device_conditions($device);

    //foreach ($conditions['cond'] as $test_id => $test)
    //{
    //  #print_vars($test);
    //  #echo('<span class="label label-info">Matched '.$test['alert_name'].'</span> ');
    //}

    $db_cs = dbFetchRows("SELECT * FROM `alert_table` WHERE `device_id` = ?", array($device['device_id']));
    foreach ($db_cs as $db_c) {
        $dbc[$db_c['entity_type']][$db_c['entity_id']][$db_c['alert_test_id']] = $db_c;
    }

    $msg .= PHP_EOL;
    $msg .= '  <h5>Checkers matching this device:</h5> ';

    foreach ($conditions['cond'] as $alert_test_id => $alert_test) {
        $msg .= '<span class="label label-info">' . $alert_test['alert_name'] . '</span> ';
        $msg_enable = TRUE;
        foreach ($alert_test['assoc'] as $assoc_id => $assoc) {
            // Check that the entity_type matches the one we're interested in.
            // echo("Matching $assoc_id (".$assoc['entity_type'].")");

            list($entity_table, $entity_id_field, $entity_name_field) = entity_type_translate($assoc['entity_type']);
            $alert = $conditions['cond'][$assoc['alert_test_id']];
            $entities = match_device_entities($device['device_id'], $assoc['entity_attribs'], $assoc['entity_type']);

            foreach ($entities AS $id => $entity) {
                $alert_table[$assoc['entity_type']][$entity[$entity_id_field]][$assoc['alert_test_id']][] = $assoc_id;
            }

            // echo(count($entities)." matched".PHP_EOL);
        }
    }

    $msg .= PHP_EOL;
    $msg .= '  <h5>Matching entities:</h5> ';

    foreach ($alert_table as $entity_type => $entities) {
        foreach ($entities as $entity_id => $entity) {
            $entity_name = entity_name($entity_type, $entity_id);
            $msg .= '<span class="label label-ok">' . htmlentities($entity_name) . '</span> ';
            $msg_enable = TRUE;

            foreach ($entity as $alert_test_id => $b) {
#        echo(str_pad($entity_type, "20").str_pad($entity_id, "20").str_pad($alert_test_id, "20"));
#        echo(str_pad(implode($b,","), "20"));
                $msg .= '<span class="label label-info">' . $conditions['cond'][$alert_test_id]['alert_name'] . '</span><br >';
                $msg_class = 'success';
                if (isset($dbc[$entity_type][$entity_id][$alert_test_id])) {
                    if ($dbc[$entity_type][$entity_id][$alert_test_id]['alert_assocs'] != implode($b, ",")) {
                        $update_array = array('alert_assocs' => implode($b, ","));
                    }
                    #echo("[".$dbc[$entity_type][$entity_id][$alert_test_id]['alert_assocs']."][".implode($b,",")."]");
                    if (is_array($update_array)) {
                        dbUpdate($update_array, 'alert_table', '`alert_table_id` = ?', array($dbc[$entity_type][$entity_id][$alert_test_id]['alert_table_id']));
                        unset($update_array);
                    }
                    unset($dbc[$entity_type][$entity_id][$alert_test_id]);
                } else {
                    $alert_table_id = dbInsert(array('device_id' => $device['device_id'], 'entity_type' => $entity_type, 'entity_id' => $entity_id, 'alert_test_id' => $alert_test_id, 'alert_assocs' => implode($b, ",")), 'alert_table');
                    //dbInsert(array('alert_table_id' => $alert_table_id), 'alert_table-state');
                }
            }
        }
    }

    $msg .= PHP_EOL;
    $msg .= "  <h5>Checking for stale entries:</h5> ";

    foreach ($dbc as $type => $entity) {
        foreach ($entity as $entity_id => $alert) {
            foreach ($alert as $alert_test_id => $data) {
                dbDelete('alert_table', "`alert_table_id` =  ?", array($data['alert_table_id']));
                //dbDelete('alert_table-state', "`alert_table_id` =  ?", array($data['alert_table_id']));
                $msg .= "-";
                $msg_enable = TRUE;
            }
        }
    }

    if ($msg_enable) {
        return (array('message' => $msg, 'class' => $msg_class));
    }
}

/**
 * Check all alerts for a device to see if they should be notified or not
 *
 * @param array device
 * @return NULL
 */
// TESTME needs unit testing
function process_alerts($device)
{
    global $config, $alert_rules, $alert_assoc;

    $pid_info = check_process_run($device); // This just clear stalled DB entries
    add_process_info($device); // Store process info

    print_cli_heading($device['hostname'] . " [" . $device['device_id'] . "]", 1);

    $alert_table = cache_device_alert_table($device['device_id']);

    $sql = "SELECT * FROM `alert_table`";
    //$sql .= " LEFT JOIN `alert_table-state` ON `alert_table`.`alert_table_id` = `alert_table-state`.`alert_table_id`";
    $sql .= " WHERE `device_id` = ? AND `alert_status` IS NOT NULL;";

    foreach (dbFetchRows($sql, array($device['device_id'])) as $entry) {
        print_cli_data_field('Alert: ' . $entry['alert_table_id']);
        print_cli('Status: [' . $entry['alert_status'] . '] ', 'color');

        // If the alerter is now OK and has previously alerted, send an recovery notice.
        if ($entry['alert_status'] == '1' && $entry['has_alerted'] == '1') {
            $alert = $alert_rules[$entry['alert_test_id']];

            if (!$alert['suppress_recovery']) {
                $log_id = log_alert('Recovery notification sent', $device, $entry, 'RECOVER_NOTIFY');
                alert_notifier($entry, "recovery", $log_id);
            } else {
                echo('Recovery suppressed.');
                $log_id = log_alert('Recovery notification suppressed', $device, $entry, 'RECOVER_SUPPRESSED');
            }

            $update_array['last_recovered'] = time();
            $update_array['has_alerted'] = 0;
            dbUpdate($update_array, 'alert_table', '`alert_table_id` = ?', array($entry['alert_table_id']));
        }

        if ($entry['alert_status'] == '0') {
            echo('Alert tripped. ');

            // Has this been alerted more frequently than the alert interval in the config?
            /// FIXME -- this should be configurable per-entity or per-checker
            if ((time() - $entry['last_alerted']) < $config['alerts']['interval'] && !isset($GLOBALS['spam'])) {
                $entry['suppress_alert'] = TRUE;
            }

            // Don't re-alert if interval set to 0
            if ($config['alerts']['interval'] == 0 && $entry['last_alerted'] != 0) {
                $entry['suppress_alert'] = TRUE;
            }

            // Check if alert has ignore_until set.
            if (is_numeric($entry['ignore_until']) && $entry['ignore_until'] > time()) {
                $entry['suppress_alert'] = TRUE;
            }
            // Check if alert has ignore_until_ok set.
            if (is_numeric($entry['ignore_until_ok']) && $entry['ignore_until_ok'] == '1') {
                $entry['suppress_alert'] = TRUE;
            }

            if ($entry['suppress_alert'] != TRUE) {
                echo('Requires notification. ');

                $log_id = log_alert('Alert notification sent', $device, $entry, 'ALERT_NOTIFY');
                alert_notifier($entry, "alert", $log_id);

                $update_array['last_alerted'] = time();
                $update_array['has_alerted'] = 1;
                dbUpdate($update_array, 'alert_table', '`alert_table_id` = ?', array($entry['alert_table_id']));

            } else {
                echo("No notification required. " . (time() - $entry['last_alerted']));
            }
        } else if ($entry['alert_status'] == '1') {
            echo("Status: OK. ");
        } else if ($entry['alert_status'] == '2') {
            echo("Status: Notification Delayed. ");
        } else if ($entry['alert_status'] == '3') {
            echo("Status: Notification Suppressed. ");
        } else {
            echo("Unknown status.");
        }
        echo(PHP_EOL);
    }

    echo(PHP_EOL);
    print_cli_heading($device['hostname'] . " [" . $device['device_id'] . "] completed notifications at " . date("Y-m-d H:i:s"), 1);

    // Clean
    del_process_info($device); // Remove process info
}

/**
 * Generate notification queue entries for alert system
 *
 * @param array entry
 * @param string Alert type
 * @param integer Log entry ID
 * @return NULL
 */
function alert_notifier($entry, $type = "alert", $log_id = NULL)
{
    global $config, $alert_rules;

    $device = device_by_id_cache($entry['device_id']);

    if (empty($log_id) && is_numeric($entry['log_id'])) {
        // Log ID can passed as argument or inside entry array
        $log_id = $entry['log_id'];
    }

    $alert = $alert_rules[$entry['alert_test_id']];

    $state = json_decode($entry['state'], TRUE);
    $conditions = json_decode($alert['conditions'], TRUE);

    $entity = get_entity_by_id_cache($entry['entity_type'], $entry['entity_id']);

    $condition_array = array();
    foreach ($state['failed'] as $failed) {
        $condition_array[] = $failed['metric'] . " " . $failed['condition'] . " " . $failed['value'] . " (" . $state['metrics'][$failed['metric']] . ")";
    }

    $metric_array = array();
    foreach ($state['metrics'] as $metric => $value) {
        $metric_array[] = $metric . ' = ' . $value;
    }

    $graphs = array();
    $graph_done = array();
    foreach ($state['metrics'] as $metric => $value) {
        if ($config['email']['graphs'] !== FALSE
            && is_array($config['entities'][$entry['entity_type']]['metric_graphs'][$metric])
            && !in_array($config['entities'][$entry['entity_type']]['metric_graphs'][$metric]['type'], $graph_done)
        ) {
            $graph_array = $config['entities'][$entry['entity_type']]['metric_graphs'][$metric];
            foreach ($graph_array as $key => $val) {
                // Check to see if we need to do any substitution
                if (substr($val, 0, 1) == '@') {
                    $nval = substr($val, 1);
                    //echo(" replaced " . $val . " with " . $entity[$nval] . " from entity. " . PHP_EOL . "<br />");
                    $graph_array[$key] = $entity[$nval];
                }
            }

            $image_data_uri = generate_alert_graph($graph_array);
            $image_url = generate_graph_url($graph_array);

            $graphs[] = array('label' => $graph_array['type'], 'type' => $graph_array['type'], 'url' => $image_url, 'data' => $image_data_uri);

            $graph_done[] = $graph_array['type'];
        }

        unset($graph_array);
    }

    if ($config['email']['graphs'] !== FALSE && count($graph_done) == 0 && is_array($config['entities'][$entry['entity_type']]['graph'])) {
        // We can draw a graph for this type/metric pair!

        $graph_array = $config['entities'][$entry['entity_type']]['graph'];
        foreach ($graph_array as $key => $val) {
            // Check to see if we need to do any substitution
            if (substr($val, 0, 1) == '@') {
                $nval = substr($val, 1);
                //echo(" replaced ".$val." with ". $entity[$nval] ." from entity. ".PHP_EOL."<br />");
                $graph_array[$key] = $entity[$nval];
            }
        }

        //print_vars($graph_array);

        $image_data_uri = generate_alert_graph($graph_array);
        $image_url = generate_graph_url($graph_array);

        $graphs[] = array('label' => $graph_array['type'], 'type' => $graph_array['type'], 'url' => $image_url, 'data' => $image_data_uri);

        unset($graph_array);
    }

    $graphs_html = "";
    foreach ($graphs as $graph) {
        $graphs_html .= '<h4>' . $graph['type'] . '</h4>';
        $graphs_html .= '<a href="' . $graph['url'] . '"><img src="' . $graph['data'] . '"></a><br />';
    }

    //print_vars($graphs);
    //print_vars($graphs_html);
    //print_vars($entry);

    $message_tags = array(
        'ALERT_STATE' => ($entry['alert_status'] == '1' ? "RECOVER" : "ALERT"),
        'ALERT_URL' => generate_url(array('page'        => 'device',
                                          'device'      => $device['device_id'],
                                          'tab'         => 'alert',
                                          'alert_entry' => $entry['alert_table_id'])),
        'ALERT_TIMESTAMP' => date('Y-m-d H:i:s'),
        'ALERT_ID' => $entry['alert_table_id'],
        'ALERT_MESSAGE' => $alert['alert_message'],
        'CONDITIONS' => implode(PHP_EOL . '             ', $condition_array),
        'METRICS' => implode(PHP_EOL . '             ', $metric_array),
        'DURATION' => ($entry['alert_status'] == '1' ? ($entry['last_ok'] > 0 ? formatUptime(time() - $entry['last_ok']) . " (" . format_unixtime($entry['last_ok']) . ")" : "Unknown")
            : ($entry['last_ok'] > 0 ? formatUptime(time() - $entry['last_ok']) . " (" . format_unixtime($entry['last_ok']) . ")" : "Unknown")),
        'ENTITY_LINK' => generate_entity_link($entry['entity_type'], $entry['entity_id'], $entity['entity_name']),
        'ENTITY_NAME' => $entity['entity_name'],
        'ENTITY_ID' => $entity['entity_id'],
        'ENTITY_TYPE' => $alert['entity_type'],
        'ENTITY_DESCRIPTION' => $entity['entity_descr'],
        //'ENTITY_GRAPHS'       => $graphs_html,          // Predefined/embedded html images
        'ENTITY_GRAPHS_ARRAY' => json_encode($graphs),  // Json encoded images array
        'DEVICE_HOSTNAME' => $device['hostname'],
        'DEVICE_ID' => $device['device_id'],
        'DEVICE_LINK' => generate_device_link($device),
        'DEVICE_HARDWARE' => $device['hardware'],
        'DEVICE_OS' => $device['os_text'] . ' ' . $device['version'] . ' ' . $device['features'],
        'DEVICE_LOCATION' => $device['location'],
        'DEVICE_UPTIME' => deviceUptime($device)
    );

    //logfile('debug.log', var_export($message, TRUE));

    $title = alert_generate_subject($device, $message_tags['ALERT_STATE'], $message_tags);
    $message_tags['TITLE'] = $title;

    $alert_id = $entry['alert_test_id'];

    $notify_status = FALSE; // Set alert notify status to FALSE by default

    $notification_type = 'alert';
    $contacts = get_alert_contacts($device, $alert_id, $notification_type);

    foreach ($contacts AS $contact) {

        // Add notification to queue
        $notification = array(
            'device_id' => $device['device_id'],
            'log_id' => $log_id,
            'aca_type' => $notification_type,
            //'severity'              => 6,
            'endpoints' => json_encode($contact),
            'message_graphs' => $message_tags['ENTITY_GRAPHS_ARRAY'],
            'notification_added' => time(),
            'notification_lifetime' => 300,                   // Lifetime in seconds
            'notification_entry' => json_encode($entry),   // Store full alert entry for use later if required (not sure that this needed)
        );
        unset($message_tags['ENTITY_GRAPHS_ARRAY']);
        $notification_message_tags = $message_tags;
        //unset($notification_message_tags['ENTITY_GRAPHS_ARRAY']);
        $notification['message_tags'] = json_encode($notification_message_tags);
        $notification_id = dbInsert($notification, 'notifications_queue');

        print_cli_data("Queueing Notification ", "[" . $notification_id . "]");

    }

}

// DOCME needs phpdoc block
// TESTME needs unit testing
function alert_generate_subject($device, $prefix, $message_tags)
{
    $subject = "$prefix: [" . $device['hostname'] . ']';

    if ($message_tags['ENTITY_TYPE']) {
        $subject .= ' [' . $message_tags['ENTITY_TYPE'] . ']';
    }
    if ($message_tags['ENTITY_NAME'] && $message_tags['ENTITY_NAME'] != $device['hostname']) {
        $subject .= ' [' . $message_tags['ENTITY_NAME'] . ']';
    }
    $subject .= ' ' . $message_tags['ALERT_MESSAGE'];

    return $subject;
}

/**
 * Get contacts associated with selected notification type and alert ID
 * Currently know notification types: alert, syslog
 *
 * @param array $device Common device array
 * @param int $alert_id Alert ID
 * @param string $notification_type Used type for notifications
 * @return array Array with transport -> endpoints lists
 */
function get_alert_contacts($device, $alert_id, $notification_type)
{
    if (!is_array($device)) {
        $device = device_by_id_cache($device);
    }

    $contacts = array();

    if (!$device['ignore'] && !get_dev_attrib($device, 'disable_notify') && !$GLOBALS['config']['alerts']['disable']['all']) {
        // figure out which transport methods apply to an alert

        $sql = "SELECT * FROM `alert_contacts`";
        $sql .= " WHERE `contact_disabled` = 0 AND `contact_id` IN";
        $sql .= " (SELECT `contact_id` FROM `alert_contacts_assoc` WHERE `aca_type` = ? AND `alert_checker_id` = ?);";

        foreach (dbFetchRows($sql, array($notification_type, $alert_id)) as $contact) {
            $contacts[] = $contact;
        }

        if (empty($contacts)) {
            // if alert_contacts table is not in use, fall back to default
            // hardcoded defaults for when there is no contact configured.

            $email = NULL;

            if ($GLOBALS['config']['email']['default_only']) {
                // default only mail
                $email = $GLOBALS['config']['email']['default'];
            } else {
                // default device contact
                if (get_dev_attrib($device, 'override_sysContact_bool')) {
                    $email = get_dev_attrib($device, 'override_sysContact_string');
                } else {
                    if (parse_email($device['sysContact'])) {
                        $email = $device['sysContact'];
                    } else {
                        $email = $GLOBALS['config']['email']['default'];
                    }
                }
            }

            if ($email != NULL) {
                $emails = parse_email($email);

                foreach ($emails as $email => $descr) {
                    $contacts[] = array('contact_endpoint' => '{"email":"' . $email . '"}', 'contact_id' => '0', 'contact_descr' => $descr, 'contact_method' => 'email');
                }
            }
        }
    }

    return $contacts;
}

function process_notifications($vars = array())
{
    global $config;

    $result = array();
    $params = array();

    $sql = 'SELECT * FROM `notifications_queue` WHERE 1';

    foreach ($vars AS $var => $value) {
        switch ($var) {
            case 'device_id':
                $sql .= ' AND `device_id` = ?';
                $params[] = $value;
                break;
            case 'notification_id':
                $sql .= ' AND `device_id` = ?';
                $params[] = $value;
                break;
            case 'aca_type':
                $sql .= ' AND `aca_type` = ?';
                $params[] = $value;
                break;
        }
    }

    /**
     * switch ($notification_type)
     * {
     * case 'alert':
     * case 'syslog':
     * // Alerts/syslog required device_id
     * $sql     .= ' AND `device_id` = ?';
     * $params[] = $device['device_id'];
     * break;
     * case 'web':
     * // Currently not used
     * break;
     * }
     **/

    foreach (dbFetchRows($sql, $params) as $notification) {

        print_debug_vars($notification);

        // Recheck if current notification is locked
        $locked = dbFetchCell('SELECT `notification_locked` FROM `notifications_queue` WHERE `notification_id` = ?', array($notification['notification_id'])); //ALTER TABLE `notifications_queue` ADD `notification_locked` BOOLEAN NOT NULL DEFAULT FALSE AFTER `notification_entry`;
        if ($locked || $locked === NULL || $locked === FALSE) // If notification not exist or column 'notification_locked' not exist this query return NULL or (possible?) FALSE
        {
            // Notification already processed by other alerter or has already been sent
            continue;
        } else {
            // Lock current notification
            dbUpdate(array('notification_locked' => 1), 'notifications_queue', '`notification_id` = ?', array($notification['notification_id']));
        }

        $notification_count = 0;
        $endpoint = json_decode($notification['endpoints'], TRUE);

        // If this notification is older than lifetime, unset the endpoints so that it is removed.
        if ((time() - $notification['notification_added']) > $notification['notification_lifetime']) {
            $endpoint = array();
        } else {
            $notification_age = time() - $notification['notification_added'];
            $notification_timeleft = $notification['notification_lifetime'] - $notification_age;
        }

        $message_tags = json_decode($notification['message_tags'], TRUE);
        $message_graphs = json_decode($notification['message_graphs'], TRUE);
        if (is_array($message_graphs) && count($message_graphs)) {
            $message_tags['ENTITY_GRAPHS_ARRAY'] = $message_graphs;
        }
        if (isset($message_tags['TIMESTAMP']) && empty($message_tags['DURATION'])) {
            $message_tags['DURATION'] = formatUptime(time() - strtotime($message_tags['TIMESTAMP'])) . ' (' . $message_tags['TIMESTAMP'] . ')';
        }

        if (isset($GLOBALS['config']['alerts']['disable'][$endpoint['contact_method']]) && $GLOBALS['config']['alerts']['disable'][$endpoint['contact_method']]) {
            $result[$method] = 'disabled';
            unset($endpoint);
            continue;
        } // Skip if method disabled globally

        $method_include = $GLOBALS['config']['install_dir'] . '/includes/alerting/' . $endpoint['contact_method'] . '.inc.php';

        if (is_file($method_include)) {
            print_cli_data("Notifying", "[" . $endpoint['contact_method'] . "] " . $endpoint['contact_descr'] . ": " . $endpoint['contact_endpoint']);

            // Split out endpoint data as stored JSON in the database into array for use in transport
            // The original string also remains available as the contact_endpoint key
            foreach (json_decode($endpoint['contact_endpoint']) as $field => $value) {
                $endpoint[$field] = $value;
            }

            include($method_include);

            // FIXME check success
            // FIXME log notification + success/failure!
            if ($notify_status['success']) {
                $result[$method] = 'ok';
                unset($endpoint);
                $notification_count++;
            } else {
                $result[$method] = 'false';
            }
        } else {
            $result[$method] = 'missing';
            unset($endpoint); // Remove it because it's dumb and doesn't exist. Don't retry it if it doesn't exist.
            print_cli_data("Missing include", $method_include);
        }

        // Remove notification from queue,
        // currently in any case, lifetime, added time and result status is ignored!
        switch ($notification_type) {
            case 'alert':
                if ($notification_count) {
                    dbUpdate(array('notified' => 1), 'alert_log', '`event_id` = ?', array($notification['log_id']));
                }
                break;
            case 'syslog':
                if ($notification_count) {
                    dbUpdate(array('notified' => 1), 'syslog_alerts', '`lal_id` = ?', array($notification['log_id']));
                }
                break;
            case 'web':
                // Currently not used
                break;
        }

        if (empty($endpoint)) {
            dbDelete('notifications_queue', '`notification_id` = ?', array($notification['notification_id']));
        } else {
            // Set the endpoints to the remaining un-notified endpoints and unlock the queue entry.
            dbUpdate(array('notification_locked' => 0, 'endpoints' => json_encode($endpoint)), 'notifications_queue', '`notification_id` = ?', array($notification['notification_id']));
        }
    }

    return $result;
}

// Use this function to write to the alert_log table
// Fix me - quite basic.
// DOCME needs phpdoc block
// TESTME needs unit testing
function log_alert($text, $device, $alert, $log_type)
{
    $insert = array('alert_test_id' => $alert['alert_test_id'],
        'device_id' => $device['device_id'],
        'entity_type' => $alert['entity_type'],
        'entity_id' => $alert['entity_id'],
        'timestamp' => array("NOW()"),
        //'status'        => $alert['alert_status'],
        'log_type' => $log_type,
        'message' => $text);

    $id = dbInsert($insert, 'alert_log');

    return $id;
}

function threshold_string($alert_low, $warn_low, $warn_high, $alert_high, $symbol = NULL)
{

  // Generate "pretty" thresholds
  if (is_numeric($alert_low))
  {
    $alert_low_t = format_value($alert_low, $format) . $symbol;
  } else {
    $alert_low_t = "&infin;";
  }

  if (is_numeric($warn_low))
  {
    $warn_low_t = format_value($warn_low, $format) . $symbol;
  } else {
    $warn_low_t = NULL;
  }

  if ($warn_low_t) { $alert_low_t = $alert_low_t . " (".$warn_low_t.")"; }

  if (is_numeric($alert_high))
  {
    $alert_high_t = format_value($alert_high, $format) . $symbol;
  } else {
    $alert_high_t = "&infin;";
  }

  if (is_numeric($warn_high))
  {
    $warn_high_t = format_value($warn_high, $format) . $symbol;
  } else {
    $warn_high_t = NULL;
  }

  if ($warn_high_t) { $alert_high_t = "(".$warn_high_t.") " . $alert_high_t; }

  $thresholds = $alert_low_t . ' - ' . $alert_high_t;

  return $thresholds;

}

function check_thresholds($alert_low, $warn_low, $warn_high, $alert_high, $value)
{

  $status = ok;

  if(is_numeric($warn_low) && $warn_low > $value)   { $status = 'warn'; }
  if(is_numeric($warn_high) && $warn_high < $value) { $status = 'warn'; }

  if(is_numeric($alert_low)  && $alert_low > $value)   { $status = 'alert'; }
  if(is_numeric($alert_high) && $alert_high < $value)  { $status = 'alert'; }

  return $status;

}


// EOF
