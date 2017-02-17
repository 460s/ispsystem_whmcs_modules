<?php
use WHMCS\Database\Capsule as DB;

function dcimanager_MetaData(){
    return array(
		'DisplayName' => 'DCImanager',
		'RequiresServer' => true,
    );
}

function dcimanager_ConfigOptions() {
	return array(
		"package" => 	array(
					"FriendlyName" => "Package Name",
					"Type" => "text",
					"Size" => "32",
				),
		"os" =>		array(
					"FriendlyName" => "Operation system",
					"Type" => "text",
					"Size" => "64",
				),
	);
}

function dci_get_external_id($params) {
	$result = DB::table('mod_ispsystem')
		->select('external_id')
		->where('serviceid', $params["serviceid"])
		->first();
	if ($result) {
		return $result->external_id;
	} else {
		return "";
	}
}

function dci_save_external_id($params, $external_id) {
	$vmid = dci_get_external_id($params);

	if ($vmid) {
		DB::table('mod_ispsystem')->where('serviceid', $params["serviceid"])->update(['external_id' => $external_id]);
	} else {
		DB::table('mod_ispsystem')->insert(['external_id' => $external_id, 'serviceid' => $params["serviceid"]]);
	}
}

function dcimanager_AdminServicesTabFields($params) {
	$value = dci_get_external_id($params);
	return array("DCImanager ID" => "<input type='text' name='dcimanager_id' size='16' value='".$value."' />");
}

function dcimanager_AdminServicesTabFieldsSave($params) {
	dci_save_external_id($params, $_POST["dcimanager_id"]);
}

function dci_api_request3($ip, $func, $param) {
	global $op;

	$default_xml_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc/>\n";
	$default_xml_error_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc><error type=\"curl\"/></doc>\n";

	$url = "https://".$ip."/dcimgr";
	$postfields = array("out" => "xml", "func" => (string)$func, );
	$options = array ('CURLOPT_TIMEOUT' => '60');
	foreach ($param as $key => &$value) {
		$value = (string)$value;
	}

	$response = curlCall($url, array_merge($postfields, $param), $options);

	logModuleCall("dcimanager:".$func, $op, array_merge($postfields, $param), $response, $response);

	$out = simplexml_load_string($default_xml_string);

	try {
		$out = new SimpleXMLElement($response);
	} catch (Exception $e) {
		$out = simplexml_load_string($default_xml_error_string);
		$out->error->addChild("msg", $e->getMessage());
	}

	return $out;
}

function dci_api_request4($ip, $auth, $func, $param) {
	global $op;

	$default_xml_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc/>\n";
	$default_xml_error_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc><error type=\"curl\"/></doc>\n";

	$url = "https://".$ip."/dcimgr";
	$postfields = array("out" => "xml", "func" => (string)$func, "auth" => (string)$auth, );
	$options = array ('CURLOPT_TIMEOUT' => '60');
	foreach ($param as $key => &$value) {
		$value = (string)$value;
	}

	$response = curlCall($url, array_merge($postfields, $param), $options);

	logModuleCall("dcimanager:".$func, $op, array_merge($postfields, $param), $response, $response);

	$out = simplexml_load_string($default_xml_string);

	try {
		$out = new SimpleXMLElement($response);
	} catch (Exception $e) {
		$out = simplexml_load_string($default_xml_error_string);
		$out->error->addChild("msg", $e->getMessage());
	}

	return $out;
}

function dci_api_request($ip, $username, $password, $func, $param) {
	global $op;

	$default_xml_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc/>\n";
	$default_xml_error_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc><error type=\"curl\"/></doc>\n";

	$url = "https://".$ip."/dcimgr";
	$postfields = array("out" => "xml", "func" => (string)$func, "authinfo" => (string)$username.":".(string)$password, );
	$options = array ('CURLOPT_TIMEOUT' => '60');
	foreach ($param as $key => &$value) {
		$value = (string)$value;
	}

	$response = curlCall($url, array_merge($postfields, $param), $options);

	logModuleCall("dcimanager:".$func, $op, array_merge($postfields, $param), $response, $response, array ($password));

	$out = simplexml_load_string($default_xml_string);

	try {
		$out = new SimpleXMLElement($response);
	} catch (Exception $e) {
		$out = simplexml_load_string($default_xml_error_string);
		$out->error->addChild("msg", $e->getMessage());
	}

	return $out;
}

function dci_find_error($xml) {
        $error = "";

        if ($xml->error) {
                $error = $xml->error["type"].":".$xml->error->msg;
        }

        return $error;
}

function dci_set_server_owner($params, $id, $user_id) {
	$server_ip = $params["serverip"];
	$server_username = $params["serverusername"];
	$server_password = $params["serverpassword"];

	$server_props = dci_api_request($server_ip, $server_username, $server_password, "server.edit", array("elid" => $id));

	return dci_api_request($server_ip, $server_username, $server_password, "server.edit",
				array(	"elid" => $id,
					"sok" => "ok",
					"mac" => $server_props->mac,
					"name" => $server_props->name,
					"notes" => $server_props->notes,
					"type" => $server_props->type,
					"owner" => $user_id));
}

function dci_set_server_domain($params, $id, $domain) {
	$server_ip = $params["serverip"];
	$server_username = $params["serverusername"];
	$server_password = $params["serverpassword"];

	$server_ip_list = dci_api_request($server_ip, $server_username, $server_password, "iplist", array("elid" => $id));
	$ip_list = $server_ip_list->xpath("//elem[not(type) or type != 'group']/id");

	$error = "";
	while(list( , $ip_id) = each($ip_list)) {
		$error = dci_find_error(dci_api_request($server_ip, $server_username, $server_password, "iplist.edit",
				array(	"elid" => (string)$ip_id,
					"sok" => "ok",
					"plid" => $id,
					"domain" => $domain)));
	}

	if ($error == "")
		return "success";
	else
		return $error;
}

function dcimanager_generate_random_string($length = 12) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $randomString;
}

function dcimanager_CreateAccount($params) {
	global $op;
	$op = "create";

	$server_ip = $params["serverip"];
	if ($server_ip == "")
		return "No server!";
	$server_username = $params["serverusername"];
	$server_password = $params["serverpassword"];

	$service_username = $params["username"];
	$user_list = dci_api_request($server_ip, $server_username, $server_password, "user", array());
	$find_user = $user_list->xpath("/doc/elem[level='16' and name='".$service_username."']");
	$user_id = $find_user[0]->id;

	$password = dcimanager_generate_random_string();

	$pwd_results = localAPI("encryptpassword", array("password2" => $password), "admin");

	DB::table('tblhosting')->where('id', $params["serviceid"])->update(['password' => $pwd_results["password"]]);

	if ($user_id == "") {
		$user_create_param = array (
						"sok" => "ok",
						"level" => "lvUser",
						"name" => $service_username,
						"passwd" => $password,
						);

		$user_create = dci_api_request($server_ip, $server_username, $server_password, "user.edit", $user_create_param);
		$user_id = $user_create->id;

		if ($user_id == "")
			return "Can not create user!";
	}

	$find_user = $user_list->xpath("/doc/elem[name='".$server_username."']");
	$admin_id = $find_user[0]->id;

	$server_list = dci_api_request($server_ip, $server_username, $server_password, "server", array());
	$find_server = $server_list->xpath("/doc/elem[(owner='' or not(owner)) and (chassis_templ='".$params["configoption1"]."' or type='".$params["configoption1"]."') and hostname='free.ds' and not(blocked) and not(hwproblem) and not(diag_in_progress)]");
	$server_id = $find_server[0]->id;

	if ($server_id == "")
		return "Can not find free server! ".$params["configoption1"].": ".count($find_server)." ".$find_server[0]->type;

	$os = $params["configoption2"];

	if (array_key_exists("os", $params["configoptions"])) {
		$os = ($params["configoptions"]["os"]);
	}

	if (array_key_exists("OS", $params["configoptions"])) {
                $os = ($params["configoptions"]["OS"]);
        }

	if (array_key_exists("ostemplate", $params["configoptions"])) {
                $os = ($params["configoptions"]["ostemplate"]);
        }

	$ip_count = 0;
	$ipv6_count = 0;

	if (array_key_exists("IP", $params["configoptions"])) {
		$ip_count += $params["configoptions"]["IP"];
        }

	if (array_key_exists("IPv6", $params["configoptions"])) {
                $ipv6_count += $params["configoptions"]["IPv6"];
        }

	dci_set_server_owner($params, $server_id, $admin_id);

	$dci_server_enable = dci_api_request($server_ip, $server_username, $server_password, "server.enable", array("elid" => $server_id));
	$error = dci_find_error($dci_server_enable);
	if ($error != "")
		return $error;
	$dci_server_poweron = dci_api_request($server_ip, $server_username, $server_password, "server.poweron", array("elid" => $server_id));
	$error = dci_find_error($dci_server_poweron);
	if ($error != "")
		return $error;

	$wait_time = 0;
	while (1) {
		$wait_time = $wait_time + 30;
		sleep(30);

		if ($wait_time > 600)
			return "Can not power on server!";

		$dci_list = dci_api_request($server_ip, $server_username, $server_password, "server", array());
		$find_dci = $dci_list->xpath("/doc/elem[id='".$server_id."' and poweron]");
		if (count($find_dci) == 0) {
		} else {
			break;
		}
	}

	dci_set_server_domain($params, $server_id, $params["domain"]);

	$dci_install = dci_api_request($server_ip, $server_username, $server_password, "server.operations",
					array(	"sok" => "ok",
						"elid" => $server_id,
						"operation" => "ostemplate",
						"ostemplate" => $os,
						"passwd" => $password,
						"confirm" => $password,
						"checkpasswd" => $password));

	$error = dci_find_error($dci_install);
	if ($error != "") {
		if (dci_set_server_domain($params, $server_id, "free.ds") != "succes")
			logActivity("DCImanager. Can not reset domain for server ".$server_id);

		return $error;
	}

	dci_save_external_id($params, $server_id);

	$dci_ip = "0.0.0.0";
	$wait_time = 0;

	while (1) {
		$dci_list = dci_api_request($server_ip, $server_username, $server_password, "server", array());
		$find_dci = $dci_list->xpath("/doc/elem[id='".$server_id."' and not(install_in_progress) and not(operation_failed)]");

		if (count($find_dci) == 0) {
		} else {
			$dci_ip = $find_dci[0]->ip;
			break;
		}

		$wait_time = $wait_time + 30;
		sleep(30);

		if ($wait_time > 7200) {
			if (dci_set_server_domain($params, $server_id, "free.ds") != "succes")
				logActivity("DCImanager. Can not reset domain for server ".$server_id);

			return "Can not install server!";
		}
	}

	DB::table('tblhosting')->where('id', $params["serviceid"])->update(['dedicatedip' => $dci_ip]);

	dci_set_server_owner($params, $server_id, $user_id);
	dci_set_server_domain($params, $server_id, $params["domain"]);

	$ip_list = "";
	while ($ip_count > 0) {
		$new_ip_param = array(
					"plid" => $server_id,
					"domain" => $params["domain"],
					"sok" => "ok",
					"iptype" => "public",
					"ip" => "",
					"family" => "ipv4",
					);

		$ip_add = dci_api_request($server_ip, $server_username, $server_password, "iplist.edit", $new_ip_param);
		$ip_list .= $ip_add->ip."\n";
		$ip_count--;
	}

	while ($ipv6_count > 0) {
                $new_ip_param = array(
                                        "plid" => $server_id,
                                        "domain" => $params["domain"],
                                        "sok" => "ok",
					"iptype" => "public",
					"ip" => "",
                                        "family" => "ipv6",
                                        );

		$ip_add = dci_api_request($server_ip, $server_username, $server_password, "iplist.edit", $new_ip_param);
		$ip_list .= $ip_add->ip."\n";
		$ipv6_count--;
        }

	DB::table('tblhosting')->where('id', $params["serviceid"])->update(['assignedips' => $ip_list]);

	return "success";
}

function dci_process_operation($func, $params) {
	$id = dci_get_external_id($params);
    if ($id == "") {
            return "Unknown server!";
    }

    $server_ip = $params["serverip"];
    $server_username = $params["serverusername"];
    $server_password = $params["serverpassword"];

    $result = dci_api_request($server_ip, $server_username, $server_password, $func, array("elid" => $id));
    $error = dci_find_error($result);

    if ($error != "") {
            return "Error";
    }

    return "success";
}

function dci_process_client_operation($func, $params) {
	$id = dci_get_external_id($params);
    if ($id == "") {
            return "Unknown server!";
    }

    $server_ip = $params["serverip"];
    $server_username = $params["serverusername"];
    $server_password = $params["serverpassword"];

    $key = strtolower(dcimanager_generate_random_string(32));
	$newkey = dci_api_request($server_ip, $server_username, $server_password, "session.newkey", array("username" => $params["username"], "key" => $key));

	$error = dci_find_error($newkey);
	if ($error != "") {
		logActivity($func." error: ".$error);
		return "Error";
	}

	$authinfo = dci_api_request3($server_ip, "auth", array("username" => $params["username"], "key" => $key));

	$auth = (string)($authinfo->auth);

    $result = dci_api_request4($server_ip, $auth, $func, array("elid" => $id));
    $error = dci_find_error($result);

    if ($error != "") {
            return "Error";
    }

    return "success";
}

function dcimanager_TerminateAccount($params) {
	global $op;
	$op = "terminate";

	$id = dci_get_external_id($params);
	if ($id == "") {
                return "Unknown server!";
        }

	if (dci_find_error(dci_process_operation("server.poweroff", $params)) != "")
		return "Can not turn off";

	$server_ip = $params["serverip"];
        $server_username = $params["serverusername"];
        $server_password = $params["serverpassword"];

	$server_list = dci_api_request($server_ip, $server_username, $server_password, "server", array());
	$main_ip_x = $server_list->xpath("/doc/elem[id='".$id."']");
	$main_ip = $main_ip_x[0]->ip;

	if ($main_ip == "")
		return "Can not get main ip!";

	$server_ip_list = dci_api_request($server_ip, $server_username, $server_password, "iplist", array("elid" => $id));
	$ip_list = $server_ip_list->xpath("//elem[(not(type) or type != 'group') and ip != '".$main_ip."']/id");

	while(list( , $ip_id) = each($ip_list)) {
		dci_api_request($server_ip, $server_username, $server_password, "iplist.delete", array("elid" => (string)$ip_id, "plid" => $id));
	}

	if (dci_find_error(dci_set_server_owner($params, $id, "no_owner")) != "")
		return "Can not set owner";

	if (dci_set_server_domain($params, $id, "free.ds") != "succes")
		logActivity("DCImanager. Can not reset domain for server ".$id);

	return "success";
}

function dcimanager_SuspendAccount($params) {
	global $op;
	$op = "suspend";
	return dci_process_operation("server.disable", $params);
}

function dcimanager_UnsuspendAccount($params) {
	global $op;
	$op = "unsusoend";
	return dci_process_operation("server.enable", $params);
}

function dcimanager_ChangePackage($params) {
        return "Error: Not supported!";
}

function dcimanager_ClientAreaCustomButtonArray() {
	return array(
				"Reboot Server" => "reboot",
				"Power off Server" => "poweroff",
				"Power on Server" => "poweron",
				"Server network off" => "networkoff",
				"Server network on" => "networkon",
				"Reinstall" => "reinstall",
			);
}

function dcimanager_AdminCustomButtonArray() {
	return dcimanager_ClientAreaCustomButtonArray();
}

function dcimanager_reinstall($params) {
        global $op;
        $op = "reinstall";

	if ($_POST["processaction"] == "on") {
		$os = $params["configoption2"];

		if (array_key_exists("os", $params["configoptions"])) {
		        $os = ($params["configoptions"]["os"]);
		}

		if (array_key_exists("OS", $params["configoptions"])) {
		        $os = ($params["configoptions"]["OS"]);
		}

		if (array_key_exists("ostemplate", $params["configoptions"])) {
		        $os = ($params["configoptions"]["ostemplate"]);
		}

		    $server_ip = $params["serverip"];
	    $server_username = $params["serverusername"];
	    $server_password = $params["serverpassword"];

		    $key = strtolower(dcimanager_generate_random_string(32));
	        $newkey = dci_api_request($server_ip, $server_username, $server_password, "session.newkey", array("username" => $params["username"], "key" => $key));

	        $error = dci_find_error($newkey);
	        if ($error != "") {
	                logActivity($op." error: ".$error);
	                return "Error";
        	   }

		    $authinfo = dci_api_request3($server_ip, "auth", array("username" => $params["username"], "key" => $key));

		    $auth = (string)($authinfo->auth);

		    $result = dci_api_request4($server_ip, $auth, "server.operations", array(  "sok" => "ok",
                                        "elid" =>  (string)dci_get_external_id($params),
                                        "operation" => "ostemplate",
                                        "ostemplate" => $os,
                                        "passwd" => $_POST["passwd"],
                                        "confirm" => $_POST["passwd"],
                                        "checkpasswd" => $_POST["passwd"]));
		    $error = dci_find_error($result);

		    if ($error != "") {
	        	    return "Error";
		    }

		return "success";
        } else {

		return array(
			'templatefile' => 'os',
		);
	}
}

function dcimanager_reboot($params) {
	global $op;
	$op = "reboot";
	return dci_process_client_operation("server.reboot", $params);
}

function dcimanager_poweroff($params) {
	global $op;
	$op = "poweroff";
	return dci_process_client_operation("server.poweroff", $params);
}

function dcimanager_poweron($params) {
	global $op;
	$op = "poweron";
	return dci_process_client_operation("server.poweron", $params);
}

function dcimanager_networkoff($params) {
	global $op;
	$op = "networkoff";

	$id = dci_get_external_id($params);
    if ($id == "") {
		return "Unknown server!";
    }

    $server_ip = $params["serverip"];
    $server_username = $params["serverusername"];
    $server_password = $params["serverpassword"];

	$server_list = dci_api_request($server_ip, $server_username, $server_password, "server.connection", array("elid" => (string)$id));

	foreach($server_list->xpath("/doc/elem[type='Switch']") as $elem) {
		dci_api_request($server_ip, $server_username, $server_password, "server.connection.off", array("plid" => (string)$id, "elid" => (string)$elem->id));
	}

	return "success";
}

function dcimanager_networkon($params) {
	global $op;
	$op = "networkon";

	$id = dci_get_external_id($params);
    if ($id == "") {
		return "Unknown server!";
    }

    $server_ip = $params["serverip"];
    $server_username = $params["serverusername"];
    $server_password = $params["serverpassword"];

	$server_list = dci_api_request($server_ip, $server_username, $server_password, "server.connection", array("elid" => (string)$id));

	foreach($server_list->xpath("/doc/elem[type='Switch']") as $elem) {
		dci_api_request($server_ip, $server_username, $server_password, "server.connection.on", array("plid" => (string)$id, "elid" => (string)$elem->id));
	}

	return "success";
}

function dcimanager_ServiceSingleSignOn(array $params){
	global $op;
	$op = "ServiceSingleSignOn";

	$server_ip = $params["serverip"];
	$server_username = $params["serverusername"];
	$server_password = $params["serverpassword"];

	try {
		$key = strtolower(dcimanager_generate_random_string(32));
		$newkey = dci_api_request($server_ip, $server_username, $server_password, "session.newkey", array("username" => $params["username"], "key" => $key));

		$error = dci_find_error($newkey);
		if ($error != "") {
			logActivity("ServiceSingleSignOn error: ".$error);
			return array(
				'success' => false,
				'errorMsg' => "Error",
			);
		}

		return array(
			'success' => true,
			'redirectTo' => "https://".$server_ip."/dcimgr?checkcookie=no&func=auth&username=".$params["username"]."&key=".$key,
        );
	} catch (Exception $e) {
		return array(
			'success' => false,
			'errorMsg' => "Error",
		);
	}
}

function dcimanager_AdminSingleSignOn(array $params){
	global $op;
	$op = "AdminSingleSignOn";

	$server_ip = $params["serverip"];
	$server_username = $params["serverusername"];
	$server_password = $params["serverpassword"];

	try {
		$key = strtolower(dcimanager_generate_random_string(32));
		$newkey = dci_api_request($server_ip, $server_username, $server_password, "session.newkey", array("username" => $server_username, "key" => $key));

        $error = dci_find_error($newkey);
		if ($error != "") {
			logActivity("AdminSingleSignOn error: ".$error);
			return array(
				'success' => false,
				'errorMsg' => $error,
			);
		}

        return array(
			'success' => true,
			'redirectTo' => "https://".$server_ip."/dcimgr?checkcookie=no&func=auth&username=".$server_username."&key=".$key,
        );
    } catch (Exception $e) {
    	return array(
			'success' => false,
			'errorMsg' => $e->getMessage(),
		);
    }
}

function dcimanager_UsageUpdate($params) {
	try {
		global $op;
		$op = "usage";

		$serverid = $params['serverid'];
		$server_ip = $params['serverip'];
		$server_username = $params['serverusername'];
		$server_password = $params['serverpassword'];

		$result = DB::table('tblhosting')
				->join('mod_ispsystem', 'mod_ispsystem.serviceid', '=', 'tblhosting.id')
				->where('tblhosting.server', '=', $serverid)
				->select('tblhosting.id', 'mod_ispsystem.external_id')
				->get();

		if ($server_ip == "") {
			logActivity("DCImanager empty IP");
			return;
		}

		if (!$result) {
			logActivity("DCImanager get stats error: ".mysql_error());
			return;
		}

		$traffic_data = dci_api_request($server_ip, $server_username, $server_password, "traffic", array("sok" => "ok",
														 "period" => "currentmonth"));

		foreach ($result as $data) {
			$external_id = $data["external_id"];

			if ($external_id == "")
				continue;


			$bwusage = 0;

			$find_in = $traffic_data->xpath("//elem[id='".$external_id."']");
			$find_out = $traffic_data->xpath("//elem[id='".$external_id."']");

			$find_in = $find_in[0]->rx;
			$find_out = $find_out[0]->tx;

			$bwusage = 1024.0 * floatval($find_in) + 1024.0 * floatval($find_out);

			if (isset($find_in['orig'])) {
				$bwusage = floatval($find_in['orig']) / 1024 / 1024 + floatval($find_out['orig']) / 1024 / 1024;
			}

			DB::table('tblhosting')
			   ->where('id', $data["id"])
			   ->update([
				   'bwusage' => $bwusage,
				   'lastupdate' => 'now()'
			   ]);
		}
	} catch (Exception $e) {
		logActivity("UsageUpdate error: ".$e->getMessage());
	}
}

?>
