<?php
require 'replication-monitor.class.php';
$replication = new Replication;
$replication->startup(array(
		'master' => array(
				'host' => '204.12.54.203',
				'user' => 'root',
				'pass' => 'Ke78962ebf',
				'testDB' => 'util_replication'
			),
		'slave' => array(
				'host' => '204.12.54.204',
				'user' => 'root',
				'pass' => 'Pt3243a8a7',
				'testDB' => 'util_replication'
			),
		));
echo $replication->html();
?>
