<?php
/*
   Copyright (c) 2005, Till Brehm, projektfarm Gmbh
   All rights reserved.

   Redistribution and use in source and binary forms, with or without modification,
   are permitted provided that the following conditions are met:

 * Redistributions of source code must retain the above copyright notice,
 this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice,
 this list of conditions and the following disclaimer in the documentation
 and/or other materials provided with the distribution.
 * Neither the name of ISPConfig nor the names of its contributors
 may be used to endorse or promote products derived from this software without
 specific prior written permission.

 THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
 INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY
 OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
 NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE,
 EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

class db extends mysqli
{
	/**#@+
     * @access private
     */
	private $_iQueryId;
	private $_iConnId;

	private $dbHost = '';  // hostname of the MySQL server
	private $dbName = '';  // logical database name on that server
	private $dbUser = '';  // database authorized user
	private $dbPass = '';  // user's password
	private $dbCharset = 'utf8';// Database charset
	private $dbNewLink = false; // Return a new linkID when connect is called again
	private $dbClientFlags = 0; // MySQL Client falgs
	/**#@-*/

	public $show_error_messages = false; // false in server, true in interface


	/* old things - unused now ////
	private $linkId = 0;  // last result of mysqli_connect()
	private $queryId = 0;  // last result of mysqli_query()
	private $record = array(); // last record fetched
	private $autoCommit = 1;    // Autocommit Transactions
	private $currentRow;  // current row number
	public $errorNumber = 0; // last error number
	public $errorMessage = ''; // last error message
	private $errorLocation = '';// last error location
	private $isConnected = false; // needed to know if we have a valid mysqli object from the constructor
	////
	*/

	// constructor
	public function __construct($host = NULL , $user = NULL, $pass = NULL, $database = NULL) {
		global $app, $conf;

		$this->dbHost = $host ? $host  : $conf['db_host'];
		$this->dbName = $database ? $database : $conf['db_database'];
		$this->dbUser = $user ? $user : $conf['db_user'];
		$this->dbPass = $pass ? $pass : $conf['db_password'];
		$this->dbCharset = $conf['db_charset'];
		$this->dbNewLink = $conf['db_new_link'];
		$this->dbClientFlags = $conf['db_client_flags'];

		$this->_iConnId = mysqli_connect($this->dbHost, $this->dbUser, $this->dbPass);
		$try = 0;
		while((!is_object($this->_iConnId) || mysqli_connect_error()) && $try < 5) {
			if($try > 0) sleep(1);

			$try++;
			$this->_iConnId = mysqli_connect($this->dbHost, $this->dbUser, $this->dbPass);
		}

		if(!is_object($this->_iConnId) || mysqli_connect_error()) {
			$this->_iConnId = null;
			$this->_sqlerror('Zugriff auf Datenbankserver fehlgeschlagen! / Database server not accessible!');
			return false;
		}
		if(!((bool)mysqli_query( $this->_iConnId, "USE $this->dbName"))) {
			$this->close();
			$this->_sqlerror('Datenbank nicht gefunden / Database not found');
			return false;
		}

		$this->_setCharset();
	}

	public function __destruct() {
		if($this->_iConnId) mysqli_close($this->_iConnId);
	}

	public function close() {
		if($this->_iConnId) mysqli_close($this->_iConnId);
		$this->_iConnId = null;
	}

	/* This allows our private variables to be "read" out side of the class */
	public function __get($var) {
		return isset($this->$var) ? $this->$var : NULL;
	}

	public function _build_query_string($sQuery = '') {
		$iArgs = func_num_args();
		if($iArgs > 1) {
			$aArgs = func_get_args();

			if($iArgs == 3 && $aArgs[1] === true && is_array($aArgs[2])) {
				$aArgs = $aArgs[2];
				$iArgs = count($aArgs);
			} else {
				array_shift($aArgs); // delete the query string that is the first arg!
			}

			$iPos = 0;
			$iPos2 = 0;
			foreach($aArgs as $sKey => $sValue) {
				$iPos2 = strpos($sQuery, '??', $iPos2);
				$iPos = strpos($sQuery, '?', $iPos);

				if($iPos === false && $iPos2 === false) break;

				if($iPos2 !== false && ($iPos === false || $iPos2 <= $iPos)) {
					$sTxt = $this->escape($sValue);

					if(strpos($sTxt, '.') !== false) $sTxt = preg_replace('/^(.+)\.(.+)$/', '`$1`.`$2`', $sTxt);
					else $sTxt = '`' . $sTxt . '`';

					$sQuery = substr_replace($sQuery, $sTxt, $iPos2, 2);
					$iPos2 += strlen($sTxt);
					$iPos = $iPos2;
				} else {
					if(is_int($sValue) || is_float($sValue)) {
						$sTxt = $sValue;
					} elseif(is_string($sValue) && (strcmp($sValue, '#NULL#') == 0)) {
						$sTxt = 'NULL';
					} elseif(is_array($sValue)) {
						$sTxt = '';
						foreach($sValue as $sVal) $sTxt .= ',\'' . $this->escape($sVal) . '\'';
						$sTxt = '(' . substr($sTxt, 1) . ')';
						if($sTxt == '()') $sTxt = '(0)';
					} else {
						$sTxt = '\'' . $this->escape($sValue) . '\'';
					}

					$sQuery = substr_replace($sQuery, $sTxt, $iPos, 1);
					$iPos += strlen($sTxt);
					$iPos2 = $iPos;
				}
			}
		}

		return $sQuery;
	}

	/**#@-*/


	/**#@+
     * @access private
     */
	private function _setCharset() {
		mysqli_query($this->_iConnId, 'SET NAMES '.$this->dbCharset);
		mysqli_query($this->_iConnId, "SET character_set_results = '".$this->dbCharset."', character_set_client = '".$this->dbCharset."', character_set_connection = '".$this->dbCharset."', character_set_database = '".$this->dbCharset."', character_set_server = '".$this->dbCharset."'");
	}

	private function _query($sQuery = '') {
		global $app;

		if ($sQuery == '') {
			$this->_sqlerror('Keine Anfrage angegeben / No query given');
			return false;
		}

		$try = 0;
		do {
			$try++;
			$ok = mysqli_ping($this->_iConnId);
			if(!$ok) {
				if(!mysqli_connect($this->dbHost, $this->dbUser, $this->dbPass, $this->dbName)) {
					if($try > 9) {
						$this->_sqlerror('DB::query -> reconnect');
						return false;
					} else {
						sleep(($try > 7 ? 5 : 1));
					}
				} else {
					$this->_setCharset();
					$ok = true;
				}
			}
		} while($ok == false);

		$aArgs = func_get_args();
		$sQuery = call_user_func_array(array(&$this, '_build_query_string'), $aArgs);

		$this->_iQueryId = mysqli_query($this->_iConnId, $sQuery);
		if (!$this->_iQueryId) {
			$this->_sqlerror('Falsche Anfrage / Wrong Query', false, 'SQL-Query = ' . $sQuery);
			return false;
		}

		return is_bool($this->_iQueryId) ? $this->_iQueryId : new db_result($this->_iQueryId, $this->_iConnId);
	}

	/**#@-*/





	/**
	 * Executes a query
	 *
	 * Executes a given query string, has a variable amount of parameters:
	 * - 1 parameter
	 *   executes the given query
	 * - 2 parameters
	 *   executes the given query, replaces the first ? in the query with the second parameter
	 * - 3 parameters
	 *   if the 2nd parameter is a boolean true, the 3rd parameter has to be an array containing all the replacements for every occuring ? in the query, otherwise the second parameter replaces the first ?, the third parameter replaces the second ? in the query
	 * - 4 or more parameters
	 *   all ? in the query are replaced from left to right by the parameters 2 to x
	 *
	 * @access public
	 * @param string  $sQuery query string
	 * @param mixed   ... one or more parameters
	 * @return db_result the result object of the query
	 */


	public function query($sQuery = '') {
		$aArgs = func_get_args();
		return call_user_func_array(array(&$this, '_query'), $aArgs);
	}

	/**
	 * Execute a query and get first result array
	 *
	 * Executes a query and returns the first result row as an array
	 * This is like calling $result = $db->query(),  $result->get(), $result->free()
	 * Use of this function @see query
	 *
	 * @access public
	 * @param string  $sQuery query to execute
	 * @param ...     further params (see query())
	 * @return array result row or NULL if none found
	 */
	public function queryOneRecord($sQuery = '') {
		if(!preg_match('/limit \d+\s*,\s*\d+$/i', $sQuery)) $sQuery .= ' LIMIT 0,1';

		$aArgs = func_get_args();
		$oResult = call_user_func_array(array(&$this, 'query'), $aArgs);
		if(!$oResult) return null;

		$aReturn = $oResult->get();
		$oResult->free();

		return $aReturn;
	}

	public function queryOne($sQuery = '') {
		return call_user_func_array(array(&$this, 'queryOneRecord'), func_get_args());
	}

	public function query_one($sQuery = '') {
		return call_user_func_array(array(&$this, 'queryOneRecord'), func_get_args());
	}

	/**
	 * Execute a query and return all rows
	 *
	 * Executes a query and returns all result rows in an array
	 * <strong>Use this with extreme care!!!</strong> Uses lots of memory on big result sets.
	 *
	 * @access public
	 * @param string  $sQuery query to execute
	 * @param ...     further params (see query())
	 * @return array all the rows in the result set
	 */
	public function queryAllRecords($sQuery = '') {
		$aArgs = func_get_args();
		$oResult = call_user_func_array(array(&$this, 'query'), $aArgs);
		if(!$oResult) return array();

		$aResults = array();
		while($aRow = $oResult->get()) {
			$aResults[] = $aRow;
		}
		$oResult->free();

		return $aResults;
	}

	public function queryAll($sQuery = '') {
		return call_user_func_array(array(&$this, 'queryAllRecords'), func_get_args());
	}

	public function query_all($sQuery = '') {
		return call_user_func_array(array(&$this, 'queryAllRecords'), func_get_args());
	}

	/**
	 * Execute a query and return all rows as simple array
	 *
	 * Executes a query and returns all result rows in an array with elements
	 * <strong>Only first column is returned</strong> Uses lots of memory on big result sets.
	 *
	 * @access public
	 * @param string  $sQuery query to execute
	 * @param ...     further params (see query())
	 * @return array all the rows in the result set
	 */
	public function queryAllArray($sQuery = '') {
		$aArgs = func_get_args();
		$oResult = call_user_func_array(array(&$this, 'query'), $aArgs);
		if(!$oResult) return array();

		$aResults = array();
		while($aRow = $oResult->get()) {
			$aResults[] = reset($aRow);
		}
		$oResult->free();

		return $aResults;
	}

	public function query_all_array($sQuery = '') {
		return call_user_func_array(array(&$this, 'queryAllArray'), func_get_args());
	}



	/**
	 * Get id of last inserted row
	 *
	 * Gives you the id of the last inserted row in a table with an auto-increment primary key
	 *
	 * @access public
	 * @return int id of last inserted row or 0 if none
	 */
	public function insert_id() {
		$iRes = mysqli_query($this->_iConnId, 'SELECT LAST_INSERT_ID() as `newid`');
		if(!is_object($iRes)) return false;

		$aReturn = mysqli_fetch_assoc($iRes);
		mysqli_free_result($iRes);

		return $aReturn['newid'];
	}



	/**
	 * get affected row count
	 *
	 * Gets the amount of rows affected by the previous query
	 *
	 * @access public
	 * @return int affected rows
	 */
	public function affected() {
		if(!is_object($this->_iConnId)) return 0;
		$iRows = mysqli_affected_rows($this->_iConnId);
		if(!$iRows) $iRows = 0;
		return $iRows;
	}



	/**
	 * Escape a string for usage in a query
	 *
	 * @access public
	 * @param string  $sString query string to escape
	 * @return string escaped string
	 */
	public function escape($sString) {
		global $app;
		if(!is_string($sString) && !is_numeric($sString)) {
			$app->log('NON-String given in escape function! (' . gettype($sString) . ')', LOGLEVEL_INFO);
			//$sAddMsg = getDebugBacktrace();
			$app->log($sAddMsg, LOGLEVEL_DEBUG);
			$sString = '';
		}

		/*$cur_encoding = mb_detect_encoding($sString);
		if($cur_encoding != "UTF-8") {
			if($cur_encoding != 'ASCII') {
				$app->log('String ' . substr($sString, 0, 25) . '... is ' . $cur_encoding . '.', LOGLEVEL_WARN);
				if($cur_encoding) $sString = mb_convert_encoding($sString, 'UTF-8', $cur_encoding);
				else $sString = mb_convert_encoding($sString, 'UTF-8');
			}
		} elseif(!PXBase::check_utf8($sString)) {
			$sString = utf8_encode($sString);
		}*/

		if($this->_iConnId) return mysqli_real_escape_string($this->_iConnId, $sString);
		else return addslashes($sString);
	}

	/**
	 *
	 *
	 * @access private
	 */
	private function _sqlerror($sErrormsg = 'Unbekannter Fehler', $sAddMsg = '') {
		global $app, $conf;

		$mysql_error = (is_object($this->_iConnId) ? mysqli_error($this->_iConnId) : mysqli_connect_error());
		$mysql_errno = (is_object($this->_iConnId) ? mysqli_errno($this->_iConnId) : mysqli_connect_errno());

		//$sAddMsg .= getDebugBacktrace();

		if($this->show_error_messages && $conf['demo_mode'] === false) {
			echo $sErrormsg . $sAddMsg;
		} else if(is_object($app) && method_exists($app, 'log')) {
				$app->log($sErrormsg . $sAddMsg . ' -> ' . $mysql_errno . ' (' . $mysql_error . ')', LOGLEVEL_WARN);
			}
	}

	public function affectedRows() {
		return $this->affected();
	}

	// returns mySQL insert id
	public function insertID() {
		return $this->insert_id();
	}


	//* Function to quote strings
	public function quote($formfield) {
		return $this->escape($formfield);
	}

	//* Function to unquotae strings
	public function unquote($formfield) {
		return stripslashes($formfield);
	}

	public function toLower($record) {
		if(is_array($record)) {
			foreach($record as $key => $val) {
				$key = strtolower($key);
				$out[$key] = $val;
			}
		}
		return $out;
	}

	public function diffrec($record_old, $record_new) {
		$diffrec_full = array();
		$diff_num = 0;

		if(is_array($record_old) && count($record_old) > 0) {
			foreach($record_old as $key => $val) {
				// if(!isset($record_new[$key]) || $record_new[$key] != $val) {
				if(@$record_new[$key] != $val) {
					// Record has changed
					$diffrec_full['old'][$key] = $val;
					$diffrec_full['new'][$key] = @$record_new[$key];
					$diff_num++;
				} else {
					$diffrec_full['old'][$key] = $val;
					$diffrec_full['new'][$key] = $val;
				}
			}
		} elseif(is_array($record_new)) {
			foreach($record_new as $key => $val) {
				if(isset($record_new[$key]) && @$record_old[$key] != $val) {
					// Record has changed
					$diffrec_full['new'][$key] = $val;
					$diffrec_full['old'][$key] = @$record_old[$key];
					$diff_num++;
				} else {
					$diffrec_full['new'][$key] = $val;
					$diffrec_full['old'][$key] = $val;
				}
			}
		}

		return array('diff_num' => $diff_num, 'diff_rec' => $diffrec_full);

	}

	/**
	 * Function to get the database-size
	 * @param string $database_name
	 * @return int - database-size in bytes
	 */


	public function getDatabaseSize($database_name) {
		global $app;
		include 'lib/mysql_clientdb.conf';
		/* Connect to the database */
		$link = mysql_connect($clientdb_host, $clientdb_user, $clientdb_password);
		if (!$link) {
			$app->log('Unable to connect to the database'.mysql_error($link), LOGLEVEL_DEBUG);
			return;
		}
		/* Get database-size from information_schema */
		$result=mysql_query("SELECT SUM(data_length+index_length) FROM information_schema.TABLES WHERE table_schema='".mysql_real_escape_string($database_name)."';", $link);
		$this->close;
		if (!$result) {
			$app->log('Unable to get the database-size'.mysql_error($link), LOGLEVEL_DEBUG);
			return;
		}
		$database_size = mysql_fetch_row($result);
		return $database_size[0];
	}

	//** Function to fill the datalog with a full differential record.
	public function datalogSave($db_table, $action, $primary_field, $primary_id, $record_old, $record_new, $force_update = false) {
		global $app, $conf;

		// Insert backticks only for incomplete table names.
		if(stristr($db_table, '.')) {
			$escape = '';
		} else {
			$escape = '`';
		}

		if($force_update == true) {
			//* We force a update even if no record has changed
			$diffrec_full = array('new' => $record_new, 'old' => $record_old);
			$diff_num = count($record_new);
		} else {
			//* get the difference record between old and new record
			$tmp = $this->diffrec($record_old, $record_new);
			$diffrec_full = $tmp['diff_rec'];
			$diff_num = $tmp['diff_num'];
			unset($tmp);
		}

		// Insert the server_id, if the record has a server_id
		$server_id = (isset($record_old['server_id']) && $record_old['server_id'] > 0)?$record_old['server_id']:0;
		if(isset($record_new['server_id'])) $server_id = $record_new['server_id'];


		if($diff_num > 0) {
			$diffstr = serialize($diffrec_full);
			if(isset($_SESSION)) {
				$username = $_SESSION['s']['user']['username'];
			} else {
				$username = 'admin';
			}
			$dbidx = $primary_field.':'.$primary_id;

			if($action == 'INSERT') $action = 'i';
			if($action == 'UPDATE') $action = 'u';
			if($action == 'DELETE') $action = 'd';
			$sql = "INSERT INTO sys_datalog (dbtable,dbidx,server_id,action,tstamp,user,data) VALUES (?, ?, ?, ?, ?, ?, ?)";
			$app->db->query($sql, $db_table, $dbidx, $server_id, $action, time(), $username, $diffstr);
		}

		return true;
	}

	//** Inserts a record and saves the changes into the datalog
	public function datalogInsert($tablename, $insert_data, $index_field) {
		global $app;

		if(is_array($insert_data)) {
			$key_str = '';
			$val_str = '';
			foreach($insert_data as $key => $val) {
				$key_str .= "`".$key ."`,";
				$val_str .= "'".$this->escape($val)."',";
			}
			$key_str = substr($key_str, 0, -1);
			$val_str = substr($val_str, 0, -1);
			$insert_data_str = '('.$key_str.') VALUES ('.$val_str.')';
		} else {
			$insert_data_str = $insert_data;
		}
		/* TODO: reduce risk of insert_data_str! */

		$old_rec = array();
		$this->query("INSERT INTO ?? $insert_data_str", $tablename);
		$index_value = $this->insertID();
		$new_rec = $this->queryOneRecord("SELECT * FROM ?? WHERE ? = ?", $tablename, $index_field, $index_value);
		$this->datalogSave($tablename, 'INSERT', $index_field, $index_value, $old_rec, $new_rec);

		return $index_value;
	}

	//** Updates a record and saves the changes into the datalog
	public function datalogUpdate($tablename, $update_data, $index_field, $index_value, $force_update = false) {
		global $app;

		$old_rec = $this->queryOneRecord("SELECT * FROM ?? WHERE ?? = ?", $tablename, $index_field, $index_value);

		if(is_array($update_data)) {
			$update_data_str = '';
			foreach($update_data as $key => $val) {
				$update_data_str .= "`".$key ."` = '".$this->escape($val)."',";
			}
			$update_data_str = substr($update_data_str, 0, -1);
		} else {
			$update_data_str = $update_data;
		}
		/* TODO: reduce risk of update_data_str */

		$this->query("UPDATE ?? SET $update_data_str WHERE ?? = ?", $tablename, $index_field, $index_value);
		$new_rec = $this->queryOneRecord("SELECT * FROM ?? WHERE ?? = ?", $tablename, $index_field, $index_value);
		$this->datalogSave($tablename, 'UPDATE', $index_field, $index_value, $old_rec, $new_rec, $force_update);

		return true;
	}

	//** Deletes a record and saves the changes into the datalog
	public function datalogDelete($tablename, $index_field, $index_value) {
		global $app;

		$old_rec = $this->queryOneRecord("SELECT * FROM ?? WHERE ?? = ?", $tablename, $index_field, $index_value);
		$this->query("DELETE FROM ?? WHERE ?? = ?", $tablename, $index_field, $index_value);
		$new_rec = array();
		$this->datalogSave($tablename, 'DELETE', $index_field, $index_value, $old_rec, $new_rec);

		return true;
	}

	//** Deletes a record and saves the changes into the datalog
	public function datalogError($errormsg) {
		global $app;

		if(isset($app->modules->current_datalog_id) && $app->modules->current_datalog_id > 0) $this->query("UPDATE sys_datalog set error = '".$this->quote($errormsg)."' WHERE datalog_id = ".$app->modules->current_datalog_id);

		return true;
	}


	public function freeResult($query)
	{
		if(is_object($query) && (get_class($query) == "mysqli_result")) {
			$query->free();
			return true;
		} else {
			return false;
		}
	}

	/*
       $columns = array(action =>   add | alter | drop
       name =>     Spaltenname
       name_new => neuer Spaltenname, nur bei 'alter' belegt
       type =>     42go-Meta-Type: int16, int32, int64, double, char, varchar, text, blob
       typeValue => Wert z.B. bei Varchar
       defaultValue =>  Default Wert
       notNull =>   true | false
       autoInc =>   true | false
       option =>   unique | primary | index)


     */

	public function createTable($table_name, $columns) {
		$index = '';
		$sql = "CREATE TABLE ?? (";
		foreach($columns as $col){
			$sql .= $col['name'].' '.$this->mapType($col['type'], $col['typeValue']).' ';

			if($col['defaultValue'] != '') $sql .= "DEFAULT '".$col['defaultValue']."' ";
			if($col['notNull'] == true) {
				$sql .= 'NOT NULL ';
			} else {
				$sql .= 'NULL ';
			}
			if($col['autoInc'] == true) $sql .= 'auto_increment ';
			$sql.= ',';
			// key Definitionen
			if($col['option'] == 'primary') $index .= 'PRIMARY KEY ('.$col['name'].'),';
			if($col['option'] == 'index') $index .= 'INDEX ('.$col['name'].'),';
			if($col['option'] == 'unique') $index .= 'UNIQUE ('.$col['name'].'),';
		}
		$sql .= $index;
		$sql = substr($sql, 0, -1);
		$sql .= ')';
		/* TODO: secure parameters */
		$this->query($sql, $table_name);
		return true;
	}

	/*
       $columns = array(action =>   add | alter | drop
       name =>     Spaltenname
       name_new => neuer Spaltenname, nur bei 'alter' belegt
       type =>     42go-Meta-Type: int16, int32, int64, double, char, varchar, text, blob
       typeValue => Wert z.B. bei Varchar
       defaultValue =>  Default Wert
       notNull =>   true | false
       autoInc =>   true | false
       option =>   unique | primary | index)


     */
	public function alterTable($table_name, $columns) {
		$index = '';
		$sql = "ALTER TABLE ?? ";
		foreach($columns as $col){
			if($col['action'] == 'add') {
				$sql .= 'ADD '.$col['name'].' '.$this->mapType($col['type'], $col['typeValue']).' ';
			} elseif ($col['action'] == 'alter') {
				$sql .= 'CHANGE '.$col['name'].' '.$col['name_new'].' '.$this->mapType($col['type'], $col['typeValue']).' ';
			} elseif ($col['action'] == 'drop') {
				$sql .= 'DROP '.$col['name'].' ';
			}
			if($col['action'] != 'drop') {
				if($col['defaultValue'] != '') $sql .= "DEFAULT '".$col['defaultValue']."' ";
				if($col['notNull'] == true) {
					$sql .= 'NOT NULL ';
				} else {
					$sql .= 'NULL ';
				}
				if($col['autoInc'] == true) $sql .= 'auto_increment ';
				$sql.= ',';
				// Index definitions
				if($col['option'] == 'primary') $index .= 'PRIMARY KEY ('.$col['name'].'),';
				if($col['option'] == 'index') $index .= 'INDEX ('.$col['name'].'),';
				if($col['option'] == 'unique') $index .= 'UNIQUE ('.$col['name'].'),';
			}
		}
		$sql .= $index;
		$sql = substr($sql, 0, -1);
		/* TODO: secure parameters */
		//die($sql);
		$this->query($sql, $table_name);
		return true;
	}

	public function dropTable($table_name) {
		$this->check($table_name);
		$sql = "DROP TABLE ??";
		return $this->query($sql, $table_name);
	}

	// gibt Array mit Tabellennamen zur�ck
	public function getTables($database_name = '') {
		if(!is_object($this->_iConnId)) return false;
		if($database_name == '') $database_name = $this->dbName;
		$tb_names = $this->queryAllArray("SHOW TABLES FROM ??", $database_name);
		return $tb_names;
	}

	// gibt Feldinformationen zur Tabelle zur�ck
	/*
       $columns = array(action =>   add | alter | drop
       name =>     Spaltenname
       name_new => neuer Spaltenname, nur bei 'alter' belegt
       type =>     42go-Meta-Type: int16, int32, int64, double, char, varchar, text, blob
       typeValue => Wert z.B. bei Varchar
       defaultValue =>  Default Wert
       notNull =>   true | false
       autoInc =>   true | false
       option =>   unique | primary | index)


     */

	function tableInfo($table_name) {

		global $go_api, $go_info;
		// Tabellenfelder einlesen

		if($rows = $go_api->db->queryAllRecords('SHOW FIELDS FROM ??', $table_name)){
			foreach($rows as $row) {
				$name = $row['Field'];
				$default = $row['Default'];
				$key = $row['Key'];
				$extra = $row['Extra'];
				$isnull = $row['Null'];
				$type = $row['Type'];


				$column = array();

				$column['name'] = $name;
				//$column['type'] = $type;
				$column['defaultValue'] = $default;
				if(stristr($key, 'PRI')) $column['option'] = 'primary';
				if(stristr($isnull, 'YES')) {
					$column['notNull'] = false;
				} else {
					$column['notNull'] = true;
				}
				if($extra == 'auto_increment') $column['autoInc'] = true;


				// Type in Metatype umsetzen

				if(stristr($type, 'int(')) $metaType = 'int32';
				if(stristr($type, 'bigint')) $metaType = 'int64';
				if(stristr($type, 'char')) {
					$metaType = 'char';
					$tmp_typeValue = explode('(', $type);
					$column['typeValue'] = substr($tmp_typeValue[1], 0, -1);
				}
				if(stristr($type, 'varchar')) {
					$metaType = 'varchar';
					$tmp_typeValue = explode('(', $type);
					$column['typeValue'] = substr($tmp_typeValue[1], 0, -1);
				}
				if(stristr($type, 'text')) $metaType = 'text';
				if(stristr($type, 'double')) $metaType = 'double';
				if(stristr($type, 'blob')) $metaType = 'blob';


				$column['type'] = $metaType;

				$columns[] = $column;
			}
			return $columns;
		} else {
			return false;
		}


		//$this->createTable('tester',$columns);

		/*
	 $result = mysql_list_fields($go_info["server"]["db_name"],$table_name);
	 $fields = mysql_num_fields ($result);
	 $i = 0;
	 $table = mysql_field_table ($result, $i);
	 while ($i < $fields) {
	 $name  = mysql_field_name  ($result, $i);
	 $type  = mysql_field_type  ($result, $i);
	 $len   = mysql_field_len   ($result, $i);
	 $flags = mysql_field_flags ($result, $i);
	 print_r($flags);

	 $columns = array(name => $name,
	 type =>     "",
	 defaultValue =>  "",
	 isnull =>   1,
	 option =>   "");
	 $returnvar[] = $columns;

	 $i++;
	 }
       */



	}

	public function mapType($metaType, $typeValue) {
		global $go_api;
		$metaType = strtolower($metaType);
		switch ($metaType) {
		case 'int16':
			return 'smallint';
			break;
		case 'int32':
			return 'int';
			break;
		case 'int64':
			return 'bigint';
			break;
		case 'double':
			return 'double';
			break;
		case 'char':
			return 'char';
			break;
		case 'varchar':
			if($typeValue < 1) die('Database failure: Lenght required for these data types.');
			return 'varchar('.$typeValue.')';
			break;
		case 'text':
			return 'text';
			break;
		case 'blob':
			return 'blob';
			break;
		}
	}

}

/**
 * database query result class
 *
 * @package pxFramework
 *
 */
class db_result {

	/**
	 *
	 *
	 * @access private
	 */
	private $_iResId = null;
	private $_iConnection = null;



	/**
	 *
	 *
	 * @access private
	 */
	public function db_result($iResId, $iConnection) {
		$this->_iResId = $iResId;
		$this->_iConnection = $iConnection;
	}



	/**
	 * get count of result rows
	 *
	 * Returns the amount of rows in the result set
	 *
	 * @access public
	 * @return int amount of rows
	 */
	public function rows() {
		if(!is_object($this->_iResId)) return 0;
		$iRows = mysqli_num_rows($this->_iResId);
		if(!$iRows) $iRows = 0;
		return $iRows;
	}



	/**
	 * Get number of affected rows
	 *
	 * Returns the amount of rows affected by the previous query
	 *
	 * @access public
	 * @return int amount of affected rows
	 */
	public function affected() {
		if(!is_object($this->_iConnection)) return 0;
		$iRows = mysqli_affected_rows($this->_iConnection);
		if(!$iRows) $iRows = 0;
		return $iRows;
	}



	/**
	 * Frees the result set
	 *
	 * @access public
	 */
	public function free() {
		if(!is_object($this->_iResId)) return;

		mysqli_free_result($this->_iResId);
		return;
	}



	/**
	 * Get a result row (associative)
	 *
	 * Returns the next row in the result set. To be used in a while loop like while($currow = $result->get()) { do something ... }
	 *
	 * @access public
	 * @return array result row
	 */
	public function get() {
		$aItem = null;

		if(is_object($this->_iResId)) {
			$aItem = mysqli_fetch_assoc($this->_iResId);
			if(!$aItem) $aItem = null;
		}
		return $aItem;
	}



	/**
	 * Get a result row (array with numeric index)
	 *
	 * @access public
	 * @return array result row
	 */
	public function getAsRow() {
		$aItem = null;

		if(is_object($this->_iResId)) {
			$aItem = mysqli_fetch_row($this->_iResId);
			if(!$aItem) $aItem = null;
		}
		return $aItem;
	}

}

/**
 * database query result class
 *
 * emulates a db result set out of an array so you can use array results and db results the same way
 *
 * @package pxFramework
 * @see db_result
 *
 *
 */
class fakedb_result {

	/**
	 *
	 *
	 * @access private
	 */
	private $aResultData = array();

	/**
	 *
	 *
	 * @access private
	 */
	private $aLimitedData = array();



	/**
	 *
	 *
	 * @access private
	 */
	public function fakedb_result($aData) {
		$this->aResultData = $aData;
		$this->aLimitedData = $aData;
		reset($this->aLimitedData);
	}



	/**
	 * get count of result rows
	 *
	 * Returns the amount of rows in the result set
	 *
	 * @access public
	 * @return int amount of rows
	 */
	// Gibt die Anzahl Zeilen zurück
	public function rows() {
		return count($this->aLimitedData);
	}



	/**
	 * Frees the result set
	 *
	 * @access public
	 */
	// Gibt ein Ergebnisset frei
	public function free() {
		$this->aResultData = array();
		$this->aLimitedData = array();
		return;
	}



	/**
	 * Get a result row (associative)
	 *
	 * Returns the next row in the result set. To be used in a while loop like while($currow = $result->get()) { do something ... }
	 *
	 * @access public
	 * @return array result row
	 */
	// Gibt eine Ergebniszeile zurück
	public function get() {
		$aItem = null;

		if(!is_array($this->aLimitedData)) return $aItem;

		if(list($vKey, $aItem) = each($this->aLimitedData)) {
			if(!$aItem) $aItem = null;
		}
		return $aItem;
	}



	/**
	 * Get a result row (array with numeric index)
	 *
	 * @access public
	 * @return array result row
	 */
	public function getAsRow() {
		return $this->get();
	}



	/**
	 * Limit the result (like a LIMIT x,y in a SQL query)
	 *
	 * @access public
	 * @param int     $iStart offset to start read
	 * @param int     iLength amount of datasets to read
	 */
	public function limit_result($iStart, $iLength) {
		$this->aLimitedData = array_slice($this->aResultData, $iStart, $iLength, true);
	}

}


?>
