<?php

/*
Copyright (c) 2012, ISPConfig UG
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

require_once('../../lib/config.inc.php');
require_once('../../lib/app.inc.php');

//* Check permissions for module
$app->auth->check_module_permissions('dashboard');

$app->uses('tform');

$type = $_GET["type"];

//if($_SESSION["s"]["user"]["typ"] == 'admin') {

	
	if($type == 'globalsearch'){
		$q = $app->db->quote($_GET["q"]);
		$authsql = " AND ".$app->tform->getAuthSQL('r');
		$modules = explode(',', $_SESSION['s']['user']['modules']);
		
		// clients
		$result_clients = _search('client', 'client');
		
		// web sites
		$result_webs = _search('sites', 'web_domain');
		
		// FTP users
		$result_ftp_users = _search('sites', 'ftp_user');
		
		// shell users
		$result_shell_users = _search('sites', 'shell_user');
		
		// databases
		/*
		$result_databases = array('cheader' => array(), 'cdata' => array());
		if(in_array('sites', $modules)){
			$sql = "SELECT * FROM web_database WHERE database_name LIKE '%".$q."%' OR database_user LIKE '%".$q."%' OR remote_ips LIKE '%".$q."%'".$authsql." ORDER BY database_name";
			$results = $app->db->queryAllRecords($sql);

			if(is_array($results) && !empty($results)){	
				$result_databases['cheader'] = array('title' => 'Databases',
														'total' => count($results),
														'limit' => count($results)
													);
				foreach($results as $result){
					$description = 'Database User: '.$result['database_user'].' - Remote IPs: '.$result['remote_ips'];
					$result_databases['cdata'][] = array('title' => $result['database_name'],
												'description' => $description,
												'onclick' => 'capp(\'sites\',\'sites/database_edit.php?id='.$result['database_id'].'\');',
												'fill_text' => strtolower($result['database_name'])
												);
				}	
			}
		}
		*/
		$result_databases = _search('sites', 'database');
		
		// email domains
		$result_email_domains = _search('mail', 'mail_domain');
		
		// email mailboxes
		$result_email_mailboxes = _search('mail', 'mail_user');
		
		// dns zones
		$result_dns_zones = _search('dns', 'dns_soa');
		
		// secondary dns zones
		$result_secondary_dns_zones = _search('dns', 'dns_slave');
		
		// virtual machines
		$result_vms = _search('vm', 'openvz_vm');
		
		// virtual machines os templates
		$result_vm_ostemplates = _search('vm', 'openvz_ostemplate');
		
		// virtual machines vm templates
		$result_vm_vmtemplates = _search('vm', 'openvz_template');
		
		// virtual machines ip addresses
		$result_vm_ip_addresses = _search('vm', 'openvz_ip');

		$json = $app->functions->json_encode(array($result_clients, $result_webs, $result_ftp_users, $result_shell_users, $result_databases, $result_email_domains, $result_email_mailboxes, $result_dns_zones, $result_secondary_dns_zones, $result_vms, $result_vm_ostemplates, $result_vm_vmtemplates, $result_vm_ip_addresses));
	}

//}

function _search($module, $section){
	global $app, $q, $authsql, $modules;
	//$q = $app->db->quote($_GET["q"]);
	//$authsql = " AND ".$app->tform->getAuthSQL('r');
	//$user_modules = explode(',', $_SESSION['s']['user']['modules']);

	$result_array = array('cheader' => array(), 'cdata' => array());
	if(in_array($module, $modules)){
		$search_fields = array();
		$desc_fields = array();
		if(is_file('../'.$module.'/form/'.$section.'.tform.php')){
			include_once('../'.$module.'/form/'.$section.'.tform.php');
			
			$category_title = $form["title"];
			$form_file = $form["action"];
			$db_table = $form["db_table"];
			$db_table_idx = $form["db_table_idx"];
			$order_by = $db_table_idx;
			
			if(is_array($form["tabs"]) && !empty($form["tabs"])){
				foreach($form["tabs"] as $tab){
					if(is_array($tab['fields']) && !empty($tab['fields'])){
						foreach($tab['fields'] as $key => $val){
							if(isset($val['searchable']) && $val['searchable'] > 0){
								$search_fields[] = $key." LIKE '%".$q."%'";
								if($val['searchable'] == 1){
									$order_by = $key;
									$title_key = $key;
								}
								if($val['searchable'] == 2){
									$desc_fields[] = $key;
								}
							}
						}
					}
				}
			}
		}
		unset($form);
		
		$where_clause = '';
		if(!empty($search_fields)){
			$where_clause = implode(' OR ', $search_fields);
		} else {
			// valid SQL query which returns an empty result set
			$where_clause = '1 = 0';
		}
		$order_clause = '';
		if($order_by != '') $order_clause = ' ORDER BY '.$order_by;
		
		$results = $app->db->queryAllRecords("SELECT * FROM ".$db_table." WHERE ".$where_clause.$authsql.$order_clause);
		
		if(is_array($results) && !empty($results)){	
			$lng_file = '../'.$module.'/lib/lang/'.$_SESSION['s']['language'].'_'.$section.'.lng';
			if(is_file($lng_file)) include($lng_file);
			$result_array['cheader'] = array('title' => $category_title,
											'total' => count($results),
											'limit' => count($results)
											);
			foreach($results as $result){
				$description = '';
				if(!empty($desc_fields)){
					$desc_items = array();
					foreach($desc_fields as $desc_field){
						if($result[$desc_field] != '') $desc_items[] = $wb[$desc_field.'_txt'].': '.$result[$desc_field];
					}
					if(!empty($desc_items)) $description = implode(' - ', $desc_items);
				}
				
				$result_array['cdata'][] = array('title' => $wb[$title_key.'_txt'].': '.$result[$title_key],
												'description' => $description,
												'onclick' => "capp('".$module."','".$module."/".$form_file."?id=".$result[$db_table_idx]."');",
												'fill_text' => strtolower($result[$title_key])
												);
			}	
		}
	}
	return $result_array;
}
		
header('Content-type: application/json');
echo $json;
?>