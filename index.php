<?php
require 'replication-monitor.class.php';
$replication = new Replication;
$replication->startup(array(
		'master' => array(
				'host' => '10.0.0.1',
				'user' => 'root',
				'pass' => 'MySuperSecretPassword',
				'testDB' => 'util_replication'
			),
		'slave' => array(
				'host' => '10.0.0.2',
				'user' => 'root',
				'pass' => 'MySuperSecretPassword',
				'testDB' => 'util_replication'
			),
		));
echo $replication->html();
?>
