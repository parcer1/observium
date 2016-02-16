<?php

/**
 * Observium
 *
 *   This file is part of Observium.
 *
 * @package    observium
 * @subpackage graphs
 * @copyright  (C) 2006-2013 Adam Armstrong, (C) 2013-2016 Observium Limited
 *
 */

include_once($config['html_dir']."/includes/graphs/common.inc.php");

$rrd_filename = get_rrd_path($device, 'app-jvmoverjmx-'.$app["app_id"].'.rrd');

$array = array(
  'UpTime'  => array('descr' => 'Uptime'),
);

$i = 0;
if (is_file($rrd_filename))
{
  foreach ($array as $ds => $data)
  {
    $rrd_list[$i]['filename'] = $rrd_filename;
    $rrd_list[$i]['descr'] = $data['descr'];
    $rrd_list[$i]['ds'] = $ds;
    $i++;
  }
} else { 
  echo("file missing: $file");  
}

$colours   = "mixed";
$nototal   = 1;
$unit_text = "Seconds";

# time is in seconds. Make it hours
$divider = 3600;

#include("includes/graphs/generic_multi_line.inc.php");
include("includes/graphs/generic_multi_simplex_separated.inc.php");

// EOF
