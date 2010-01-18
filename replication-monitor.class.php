<?php
/**
 * PHP MySQL Master/Slave Replication Monitor
 * http://code.google.com/p/php-mysql-master-slave-replication-monitor
 *
 * Copyright (c) 2010 Alan Blount (alan[at]zeroasterisk[dot]com)
 * MIT Licensed.
 * http://www.opensource.org/licenses/mit-license.php
 *
 * @version: 1.0
 * Date: 2010.01.07 18:48 -0500
 *
 * ABOUT
 * http://code.google.com/p/php-mysql-master-slave-replication-monitor/
 * INSTALLATION
 * http://code.google.com/p/php-mysql-master-slave-replication-monitor/wiki/InstallationAndStandardUseCase
 */

class Replication {
	var $config = array(
		'master' => array(
				'host' => 'db-master',
				'user' => 'root',
				'pass' => 'MySuperSecretPassword',
				'testDB' => 'util_replication'
			),
		'slave' => array(
				'host' => 'db-slave',
				'user' => 'root',
				'pass' => 'MySuperSecretPassword',
				'testDB' => 'util_replication'
			),
		);
	var $slaveRestarted = false;
	var $slaveSkipStarted = false;
	var $slaveLoadedFromMaster = false;
	var $cancelRestarts = false;
	var $conn = array('master'=>null,'slave'=>null);
	var $debug = array();
	var $errors = array();
	/**
	* This is the setup method
	* @param mixed $config (optional) setup the config here
	*/
	function startup($config=null) {
		if (is_array($config) && 
			isset($config['master']) && isset($config['slave']) &&
			is_array($config['master']) && is_array($config['slave'])) {
			$this->config['master'] = array_merge($this->config['master'],$config['master']);
			$this->config['slave'] = array_merge($this->config['slave'],$config['slave']);
		}
		return $this->mysqlConnect('master') && $this->mysqlConnect('slave');
	}
	# ===========================================================
	# tests and automation controller
	# ===========================================================
	/**
	* tests the slave status to ensure it's "on"
	* @return bool
	*/
	function testStatus() {
		$status_slave = $this->query("slave","SHOW SLAVE STATUS;");
		//$status_master = $this->query("master","SHOW MASTER STATUS;");
		//$this->debug[] = array('$status_master' => $status_master,'$status_slave' => $status_slave,);
		if ($status_slave[0]['Slave_IO_Running'] != 'Yes') {
			$this->errors[] =  "<b>Slave_IO_Running {$status_slave[0]['Slave_IO_Running']}</b>";
			return false;
		}
		if ($status_slave[0]['Slave_SQL_Running'] != 'Yes') {
			$this->errors[] =  "<b>Slave_SQL_Running {$status_slave[0]['Slave_SQL_Running']}</b>";
			return false;
		}
		$this->cancelRestarts = true;
		$this->debug[] = 'testStatus()==true';
		return true;
	}
	/**
	* tests the replication by inserting a row on master and checking on slave
	* @return bool
	*/
	function testReplication() {
		$results = array();
		$created = date("Y-m-d H:i:s");
		$data = md5(time().rand(0,100));
		// insert new row on master
		$newId = $this->query("master","insert into `test` set `created` = '".$created."', `data` = '".$data."'");
		// verify: select row on master
		$rows_master = $results[] = $this->query("master","SELECT * FROM `test` WHERE `created` = '".$created."' AND `data` = '".$data."'");
		// select row on slave
		$rows_slave = $results[] = $this->query("slave","SELECT * FROM `test` WHERE `created` = '".$created."' AND `data` = '".$data."'");
		// test and compare
		if (!is_int($newId) || $newId <= 0 ) {
			$this->debug[] = $this->errors[] =  "Could Not Insert New Test Row";
			return false;
		}
		if (!is_array($rows_master) || empty($rows_master)) {
			$this->debug[] = $this->errors[] =  "Could Not Query Master ".var_export($rows_master,true);
			return false;
		}
		if (count($rows_master) !== 1) {
			$this->debug[] = $this->errors[] =  "Master Query Returned ".count($rows_master)." rows";
			return false;
		}
		if (!is_array($rows_slave) || empty($rows_slave)) {
			$this->debug[] = $this->errors[] =  "Could Not Query Slave ".var_export($rows_slave,true);
			return false;
		}
		if (count($rows_master) !== 1) {
			$this->debug[] = $this->errors[] =  "Slave Query Returned ".count($rows_master)." rows";
			return false;
		}
		if ($rows_master[0]['id']!=$rows_slave[0]['id'] || $rows_master[0]['id']!=$newId) {
			$this->debug[] = $this->errors[] =  "Mismatched ID: master={$rows_master[0]['id']} slave={$rows_slave[0]['id']} insert={$newId}";
			return false;
		}
		if ($rows_master[0]['created']!=$rows_slave[0]['created'] || $rows_master[0]['created']!=$created) {
			$this->debug[] = $this->errors[] =  "Mismatched created: master={$rows_master[0]['created']} slave={$rows_slave[0]['created']} set={$created}";
			return false;
		}
		if ($rows_master[0]['data']!=$rows_slave[0]['data'] || $rows_master[0]['data']!=$data) {
			$this->debug[] = $this->errors[] =  "Mismatched data: master={$rows_master[0]['data']} slave={$rows_slave[0]['data']} set={$data}";
			return false;
		}
		$this->debug[] = 'testReplication()==true';
		return true;
	}
	/**
	* automation contoller, does automated testing, and if failure, attempts to recover and retest
	* @return bool
	*/
	function automatedTestAndRepair() {
		if ($this->testStatus() && $this->testReplication()) {
			return true;
		} else {
			$this->actionRestartSlave();
			if ($this->testStatus() && $this->testReplication()) {
				return true;
			} else {
				return false;
			}
		}
	}
	# ===========================================================
	# actions
	# ===========================================================
	/**
	* action, restart the slave 
	* @return bool [testStatus()]
	*/
	function actionRestartSlave($fileKey=null,$posKey=null) {
		if (!$this->testStatus() && !$this->cancelRestarts) {
			$this->actionRestartSlaveFromSlave();
			if (!$this->testStatus() && !$this->cancelRestarts) {
				// still failing?  Try some other positions
				$this->actionRestartSlaveFromSlave(null,'Read_Master_Log_Pos');
				if (!$this->testStatus() && !$this->cancelRestarts) {
					$this->actionRestartSlaveFromSlave(null,'Relay_Log_Pos');
					if (!$this->testStatus() && !$this->cancelRestarts) {
						$this->actionRestartSlaveFromSlave('Relay_Master_Log_File');
						if (!$this->testStatus() && !$this->cancelRestarts) {
							// still failing?  Try from the Master
							$this->actionRestartSlaveFromMaster();
						}
					}
				}
			}
		}
		$this->debug[] = 'actionRestartSlave($fileKey,$posKey)==finished';
		return $this->testStatus();
	}
	/**
	* action, restart the slave - file and position from slave status
	* @param string $fileKey [Master_Log_File]
	* @param string $posKey [Exec_Master_Log_Pos]
	* @return bool [testStatus()]
	*/
	function actionRestartSlaveFromSlave($fileKey=null,$posKey=null) {
		if (!$this->cancelRestarts) {
			$fileKey = (!empty($fileKey) ? $fileKey : 'Master_Log_File');
			$posKey = (!empty($posKey) ? $posKey : 'Exec_Master_Log_Pos');
			$this->slaveRestarted = true;
			$this->query("slave","STOP SLAVE;");
			$this->query("slave","RESET SLAVE;");
			// get info from slave
			$status_slave = $this->query("slave","SHOW SLAVE STATUS;");
			$this->query("master","UNLOCK TABLES;");
			$this->query("slave","STOP SLAVE;");
			$this->query("slave","RESET SLAVE;");
			if (isset($status_slave[0][$fileKey]) && !empty($status_slave[0][$fileKey]) &&
				isset($status_slave[0][$posKey]) && !empty($status_slave[0][$posKey])) {
				$this->query("slave","CHANGE MASTER TO 
						MASTER_LOG_FILE='{$status_slave[0][$fileKey]}', 
						MASTER_LOG_POS={$status_slave[0][$posKey]}
						;");
			}
			$this->query("slave","START SLAVE;");
			$this->query("slave","START SLAVE IO_THREAD;");
			$this->query("slave","START SLAVE SQL_THREAD;");
			// sometimes there's a stuck record, a duplicate...
			$this->actionSkipStartSlaveIfBadStatus();
		}
		$this->debug[] = 'actionRestartSlaveFromSlave($fileKey,$posKey)==finished';
		return $this->testStatus();
	}
	/**
	* action, restart the slave - file and position from master status
	* @return bool [testStatus()]
	*/
	function actionRestartSlaveFromMasterPosition() {
		if (!$this->cancelRestarts) {
			$this->slaveRestarted = true;
			$this->query("slave","STOP SLAVE;");
			$this->query("slave","RESET SLAVE;");
			// get info from master
			$this->query("master","FLUSH PRIVILEGES;");
			$this->query("master","FLUSH TABLES WITH READ LOCK;");
			$status_master = $this->query("master","SHOW MASTER STATUS;");
			$this->query("master","UNLOCK TABLES;");
			$this->query("slave","STOP SLAVE;");
			$this->query("slave","RESET SLAVE;");
			if (isset($status_master[0]['File']) && !empty($status_master[0]['File']) &&
				isset($status_master[0]['Position']) && !empty($status_master[0]['Position'])) {
				$this->query("slave","CHANGE MASTER TO 
						MASTER_LOG_FILE='{$status_master[0]['File']}', 
						MASTER_LOG_POS={$status_master[0]['Position']}
						;");
			}
			$this->query("slave","START SLAVE;");
			$this->query("slave","START SLAVE IO_THREAD;");
			$this->query("slave","START SLAVE SQL_THREAD;");
			// sometimes there's a stuck record, a duplicate...
			$this->actionSkipStartSlaveIfBadStatus();
		}
		$this->debug[] = 'actionRestartSlaveFromMasterPosition()==finished';
		return $this->testStatus();
	}
	/**
	* action, if there's a bad status, "skip one" and restart
	*	loop through this function 10 times attempting to start replication again
	* 		(breaks out of loop if status is good)
	* @return bool [testStatus()]
	*/
	function actionSkipStartSlaveIfBadStatus() {
		if (!$this->cancelRestarts) {
			$loop = 0;
			while (!$this->testStatus() && !$this->cancelRestarts && $loop < 10) {
				$this->slaveSkipStarted = true;
				$this->query("slave","FLUSH PRIVILEGES;");
				$this->query("master","FLUSH PRIVILEGES;");
				$this->query("slave","STOP SLAVE;");
				$this->query("slave","RESET SLAVE;");
				$this->query("slave","SET GLOBAL SQL_SLAVE_SKIP_COUNTER=1;");
				$this->query("slave","START SLAVE;");
				$this->query("slave","START SLAVE IO_THREAD;");
				$this->query("slave","START SLAVE SQL_THREAD;");
				$loop++;
				//sleep(1);
			}
		}
		return $this->testStatus();
	}
	/**
	* action, this preforms a write/insert to the Slave which will Break Replication
	* WARNING: this really does break replication... only useful for testing
	* @return bool [true]
	*/
	function actionBreakReplication() {
		$this->slaveLoadedFromMaster = true;
		$newId = $this->query("slave","insert into `test` set `created` = '".date("Y-m-d H:i:s")."', `data` = 'actionBreakReplication';");
		return true;
	}
	/**
	* action, rebuild slave
	* WARNING: this will delete everything from the slave
	* WARNING: this can be really slow and lock up the server while running.
	* @return bool [true]
	*/
	function actionRebuildSlave() {
		$this->slaveLoadedFromMaster = true;
		$this->actionDeleteSlave();
		$this->actionLoadDataFromMaster();
		$this->mysqlConnect('slave');
		$this->actionRestartSlaveFromMasterPosition();
		return true;
	}
	/**
	* action, this will delete everything from your slave
	* WARNING: this really does it... only useful for testing
	* @return bool [true]
	*/
	function actionDeleteSlave() {
		$this->slaveLoadedFromMaster = true;
		$this->query("slave","STOP SLAVE;");
		$databases = $this->query("slave","SHOW DATABASES;");
		foreach ( $databases as $db_data ) { 
			$db_name = array_pop($db_data);
			if (!in_array($db_name,array('information_schema','mysql'))) {
				$this->query("slave","DROP DATABASE `$db_name`;");
			}
		}
		return true;
	}
	/**
	* action, executes "Load Data From Master"
	* WARNING: this can be really slow and lock up the server while running.
	* @return bool [true]
	*/
	function actionLoadDataFromMaster() {
		$this->slaveLoadedFromMaster = true;
		$this->query("slave","LOAD DATA FROM MASTER;");
		return true;
	}
	# ===========================================================
	# HTML
	# ===========================================================
	/**
	* HTML user interface
	* @param string $action
	*				monitor - returns just success or failure
	*				automate - returns just success or failure, but if failure, it tries to restart and recover and re-test
	*				RestartSlave
	*				SkipStartSlaveIfBadStatus
	*				LoadDataFromMaster
	* @return string $HTML
	*/
	function html($action=null) {
		$action = (empty($action) && isset($_GET['action']) ? $_GET['action'] : $action);
		$return = array();
		if ($action=='Test' || $action=='monitor') {
			// short response, to be used by a cron monitoring job perhaps
			$return[] = $this->htmlRenderTestResults();
		} elseif ($action=='TestAndHeal' || $action=='automate') {
			// short response, to be used by a cron monitoring job perhaps
			$this->automatedTestAndRepair();
			$return[] = $this->htmlRenderTestResults();
		} elseif ($action=='TestHTML') {
			$return[] = "<hr/>Test Only: <b>".$this->htmlRenderTestResults()."</b>";
			$return[] = $this->htmlRenderOptions();
			$return[] = $this->htmReturnErrors();
		} elseif ($action=='TestAndHealHTML') {
			$this->automatedTestAndRepair();
			$return[] = "<hr/>TestAndHeal: <b>".$this->htmlRenderTestResults()."</b>";
			$return[] = $this->htmlRenderOptions();
			$return[] = $this->htmReturnErrors();
		} elseif ($action=='TestAndHealHTML') {
			$this->automatedTestAndRepair();
			$return[] = "<hr/>TestAndHeal: <b>".$this->htmlRenderTestResults()."</b>";
			$return[] = $this->htmlRenderOptions();
			$return[] = $this->htmReturnErrors();
		} elseif ($action=='RestartSlave') {
			$this->actionRestartSlave();
			$return[] = "<hr/>RestartSlave: <b>".$this->htmlRenderTestResults()."</b>";
			$return[] = $this->htmlRenderOptions();
			$return[] = $this->htmReturnErrors();
		} elseif ($action=='SkipStartSlaveIfBadStatus') {
			$this->actionSkipStartSlaveIfBadStatus();
			$return[] = "<hr/>SkipStartSlaveIfBadStatus: <b>".$this->htmlRenderTestResults()."</b>";
			$return[] = $this->htmlRenderOptions();
			$return[] = $this->htmReturnErrors();
		} elseif ($action=='BreakReplication') {
			$this->actionBreakReplication();
			$return[] = "<hr/>BreakReplication: <b>".$this->htmlRenderTestResults()."</b>";
			$return[] = $this->htmlRenderOptions();
			$return[] = $this->htmReturnErrors();
		} elseif ($action=='RebuildSlave') {
			$this->actionRebuildSlave();
			$return[] = "<hr/>RebuildSlave: <b>".$this->htmlRenderTestResults()."</b>";
			$return[] = $this->htmlRenderOptions();
			$return[] = $this->htmReturnErrors();
		} elseif ($action=='LoadDataFromMaster') {
			$this->actionLoadDataFromMaster();
			$return[] = "<hr/>LoadDataFromMaster: <b>finished</b>";
			$return[] = $this->htmlRenderOptions();
			$return[] = $this->htmReturnErrors();
		} elseif ($action=='DeleteSlave') {
			$this->actionDeleteSlave();
			$return[] = "<hr/>DeleteSlave: <b>finished</b>";
			$return[] = $this->htmlRenderOptions();
			$return[] = $this->htmReturnErrors();
		} else {
			$return[] = "<hr/>TESTING: <b>".$this->htmlRenderTestResults()."</b>";
			$return[] = $this->htmlRenderOptions();
			$return[] = $this->htmReturnErrors();
		}
		return implode("\n",$return);
	}
	/**
	* HTML helper, does the testing, and returns either success or failure and a few options for further action
	* @return string $HTML
	*/
	function htmlRenderTestResults() {
		if ($this->testStatus() && $this->testReplication()) {
			return "Success";
		} else {
			return "Failure";
		}
	}
	/**
	* HTML helper, renders options
	* @return string $HTML
	*/
	function htmlRenderOptions() {
		return "<br/>
		CRON MONITORING ACTIONS:
		<a href='?action=Test'>(test)</a> &nbsp;
		<a href='?action=TestAndHeal'>(test & heal)</a>
		<br/>
		BROWSER ACTIONS:
		<a href='?action=TestHTML'>Test</a>
		<a href='?action=TestAndHealHTML'>Test & Heal</a>
		<a href='?action=RestartSlave'>Restart Slave</a>
		<a href='?action=SkipStartSlaveIfBadStatus'>Skip Start Slave</a>
		<a href='?action=BreakReplication' style='color:darkred;font-size:70%;'>Break Replication (destructive)</a>
		<a href='?action=RebuildSlave' style='color:darkred;font-size:70%;'>Rebuild Slave (destructive)</a>
		&nbsp;
		<a href='http://code.google.com/p/php-mysql-master-slave-replication-monitor/wiki/Help'><i>(help)</i></a>
		";
	}
	/**
	* HTML returns logged errors in an easy to read manner... also returns debugs
	* @return string $HTML
	*/
	function htmReturnErrors() {
		if (!empty($this->errors)) {
			$return = array("<hr/>ERRORS: <pre>");
			foreach ( $this->errors as $error ) { 
				if (is_string($error)) {
					$return[] = $error;
				} else {
					$return[] = var_export($error,true);
				}
			}
			$return[] = "</pre>";
			$return[] = $this->htmReturnDebugs();
			return implode("\n\n",$return);
		}
		return '';
	}
	/**
	* HTML returns logged debugs in an easy to read manner... 
	* @return string $HTML
	*/
	function htmReturnDebugs() {
		if (!empty($this->debug)) {
			$return = array("<hr/>DEBUGS: <pre style='font-size:80%;'>");
			foreach ( $this->debug as $debug ) { 
				if (is_string($debug)) {
					$return[] = $debug;
				} else {
					$return[] = var_export($debug,true);
				}
			}
			$return[] = "</pre>";
			return implode("\n\n",$return);
		}
		return '';
	}
	# ===========================================================
	# helpers
	# ===========================================================
	function mysqlConnect($key) {
		$this->conn[$key] = mysql_connect($this->config[$key]['host'], $this->config[$key]['user'], $this->config[$key]['pass']);
		if (!$this->conn[$key]) {
			$this->errors[] = "Unable to connect to DB: " . mysql_error() . "[". mysql_errno() ."]";
			$this->errors[] = $this->config[$key];
			var_export($this->conn[$key]);
			return false;
		}
		$this->debug[] = "mysqlConnect($key): {$this->config[$key]['user']}@{$this->config[$key]['host']}: success";
		if (!mysql_select_db($this->config[$key]['testDB'],$this->conn[$key])) {
			$this->errors[] =  "Unable to select mydbname: " . mysql_error() . "[". mysql_errno() ."]";
			return false;
		}
		$this->debug[] = "mysqlConnect($key): mysql_select_db{$this->config[$key]['testDB']}: success";
		return true;
	}
	function query($key,$sql) {
		$this->debug[] = "query($key,$sql)";
		$result = mysql_query($sql,$this->conn[$key]);
		if (!$result) {
			$error = $this->errors[] = "Could not successfully run query [$key] {{{ $sql }}} - " . mysql_error() . "[". mysql_errno() ."]";
			$this->debug[] = "query-return: error: " . $error;
			return false;
		}
		if (strtolower(substr($sql,0,6)) == "insert") {
			$return = mysql_insert_id($this->conn[$key]);
			$this->debug[] = "query-return: mysql_insert_id: $return";
			return $return;
		}
		if (in_array(strtolower(substr($sql,0,6)),array("update","change")) || 
			in_array(strtolower(substr($sql,0,5)),array("start","reset","stop ","set g","load "))) {
			$return =  mysql_affected_rows($this->conn[$key]);
			$this->debug[] = "query-return: mysql_affected_rows: $return";
			return $return;
		}
		if (mysql_num_rows($result) == 0) {
			$this->debug[] =  "query-return: No rows found: return: array()";
			return array();
		}
		
		$return = array();
		while ($row = mysql_fetch_assoc($result)) {
			$return[] = $row;
		}
		mysql_free_result($result);
		$this->debug[] =  "<i>	query-return: data:</i> ".var_export($return,true);
		return $return;
	}
}

/*
NOTES: 

http://dev.mysql.com/doc/refman/5.0/en/show-slave-status.html

http://bugs.mysql.com/bug.php?id=26540

If the slave server crashes (not mysqld process) then the syncronisation point will be lost and can not be recoved. This effectively requires the slave to be rebuilt. Very nasty. MySQL SHOULD be flushing the .info files on every commit to ensure consistency.
If not done the difference between the “.info” files and the real committed last transaction can be as long as 30 seconds. When the slave starts up it will try to reapply transactions from 30 seconds previously…
Tell your slaves not to crash and don’t pull the power on them. If they do, you’ll need to rebuild them.
*/
?>
