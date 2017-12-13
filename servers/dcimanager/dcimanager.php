<?php
/*
 *  Module Version: 7.2.0
 */
require_once 'lib/server.php';
require_once 'lib/functions.php';

use WHMCS\Database\Capsule as DB;
use Carbon\Carbon as DateTime;

function dcimanager_MetaData()
{
	return [
		'DisplayName' => 'DCImanager',
		'RequiresServer' => true,
		'ServiceSingleSignOnLabel' => 'Login to DCImanager',
		'AdminSingleSignOnLabel' => 'Login to DCImanager',
	];
}

function dcimanager_ConfigOptions()
{
	return [
		"package" => [
			"FriendlyName" => "Package Name",
			"Type" => "text",
			"Size" => "32",
		],
		"os" => [
			"FriendlyName" => "Operation system",
			"Type" => "text",
			"Size" => "64",
		],
		"recipe" => [
			"FriendlyName" => "Recipe Name",
			"Type" => "text",
			"Size" => "64",
			"Default" => "null",
		],
		"waiter" => [
			"FriendlyName" => "Dont wait the OS install",
			"Type" => "yesno",
			"Description" => "Activate service without waiting for the OS installation",
		],
		"domain_template" => [
			"FriendlyName" => "Domain template",
			"Type" => "text",
			"Size" => "32",
			"Description" => "<br/><br/>@ID@ - service id<br/>@DOMAIN@ - domain name",
		],
		"ip_address_group" => [
			"FriendlyName" => "IP address group",
			"Type" => "text",
			"Size" => "32",
			"Default" => "public"
		],
	];
}

function dcimanager_AdminServicesTabFields($params)
{
	$value = dci_get_external_id($params);
	$serverParam = GetServerParam($params["serviceid"]);

	return [
		"DCImgr Server ID" => "<input type='text' name='dcimanager_id' size='16' value='" . $value . "' /> 
			You can use this field to force the server selection before the service is activated",
		"DCImgr User Password" => DecryptPassword($params['model']->serviceProperties->get('UserPassword')),
		"Label" => $serverParam->label,
		"IPMI IP" => $serverParam->ipmi_ip,
		"Switch Port" => $serverParam->switch_port,
		"MAC" => $serverParam->mac,

	];
}

function dcimanager_AdminServicesTabFieldsSave($params)
{
	$current_id = dci_get_external_id($params);
	$new_id = $_POST["dcimanager_id"];
	if ($current_id != $new_id) {
		dci_save_external_id($params, $new_id);
		SetServerParam($new_id, $params);
	}
}


function dcimanager_CreateAccount($params)
{
	global $op;
	$op = "create";

	$server_ip = $params["serverip"];
	if ($server_ip == "") return "No server!";

	$server_username = $params["serverusername"];
	$recipe = $params["configoption3"] === "" ? "null" : $params["configoption3"];
	if (!empty($params["configoption5"])) {
		$params["domain"] = GenerateDomain($params);
	} elseif (empty($params["domain"])) {
		$params["configoption5"] = "@ID@.domain";
		$params["domain"] = GenerateDomain($params);
	}

	$user_data = CheckSimilarServers($params);
	if ($user_data) {
		$service_username = $user_data->username;
		$password = DecryptPassword($user_data->password);
		if (empty($password)) return "cant decrypt password";
		DB::table('tblhosting')
			->where('id', $params["serviceid"])
			->update(['username' => $service_username]);
	} else {
		$service_username = $params["username"];
		$password = $params["password"];
	}
	$params['model']->serviceProperties->save(["UserPassword" => EncriptPassword($password)]);

	$server = new Server($params);
	$user_list = $server->AuthInfoRequest("user");
	$find_user = $user_list->xpath("/doc/elem[level='16' and name='" . $service_username . "']");
	$user_id = $find_user[0]->id;

	if ($user_id == "") {
		$user_create_param = [
			"sok" => "ok",
			"level" => "lvUser",
			"name" => $service_username,
			"passwd" => $password,
		];

		$user_create = $server->AuthInfoRequest("user.edit", $user_create_param);
		$user_id = $user_create->id;

		if ($user_id == "")
			return "Can not create user!";
	}

	$find_user = $user_list->xpath("/doc/elem[name='" . $server_username . "']");
	$admin_id = $find_user[0]->id;

	$ext_id = dci_get_external_id($params);
	if (!empty($ext_id)) {
		$server_id = $ext_id;
	} else {
		$server_list = $server->AuthInfoRequest("server");
		$find_server = $server_list->xpath("/doc/elem[(owner='' or not(owner)) and (chassis_templ='" . $params["configoption1"] . "' or type='" . $params["configoption1"] . "') and hostname='free.ds' and not(blocked) and not(hwproblem) and not(diag_in_progress)]");
		$server_id = $find_server[0]->id;
	}


	if ($server_id == "")
		return "Can not find free server! " . $params["configoption1"] . ": " . count($find_server) . " " . $find_server[0]->type;

	$os = $params["configoption2"];

	if (array_key_exists("os", $params["configoptions"]))
		$os = ($params["configoptions"]["os"]);
	if (array_key_exists("OS", $params["configoptions"]))
		$os = ($params["configoptions"]["OS"]);
	if (array_key_exists("ostemplate", $params["configoptions"]))
		$os = ($params["configoptions"]["ostemplate"]);
	if (array_key_exists("recipe", $params["configoptions"]))
		$recipe = ($params["configoptions"]["recipe"]);


	$ip_count = 0;
	$ipv6_count = 0;

	if (array_key_exists("IP", $params["configoptions"])) {
		$ip_count += $params["configoptions"]["IP"];
	}

	if (array_key_exists("IPv6", $params["configoptions"])) {
		$ipv6_count += $params["configoptions"]["IPv6"];
	}

	dci_set_server_owner($params, $server_id, $admin_id);

	$dci_server_enable = $server->AuthInfoRequest("server.enable", ["elid" => $server_id]);
	$error = $server->errorCheck($dci_server_enable);
	if ($error != "")
		return $error;
	$dci_server_poweron = $server->AuthInfoRequest("server.poweron", ["elid" => $server_id]);
	$error = $server->errorCheck($dci_server_poweron);
	if ($error != "")
		return $error;

	$wait_time = 0;
	while (1) {
		$wait_time = $wait_time + 30;
		sleep(30);

		if ($wait_time > 600)
			return "Can not power on server!";
		$dci_list = $server->AuthInfoRequest("server");
		$find_dci = $dci_list->xpath("/doc/elem[id='" . $server_id . "' and poweron]");
		if (count($find_dci) == 0) {
		} else {
			break;
		}
	}

	$set_domain_result = dci_set_server_domain($params, $server_id, $params["domain"]);
	if (!empty($set_domain_result)) return $set_domain_result;

	AddIpToServer($ip_count, "ipv4", $server_id, $params);
	AddIpToServer($ipv6_count, "ipv6", $server_id, $params);

	$dci_install = $server->AuthInfoRequest("server.operations",
		["sok" => "ok",
			"elid" => $server_id,
			"operation" => "ostemplate",
			"ostemplate" => $os,
			"passwd" => $params["password"],
			"confirm" => $params["password"],
			"checkpasswd" => $params["password"],
			"recipe" => $recipe
		]);

	$error = $server->errorCheck($dci_install);
	if ($error != "") {
		if (!empty(dci_set_server_domain($params, $server_id, "free.ds")))
			logActivity("DCImanager. Can not reset domain for server " . $server_id);

		return $error;
	}

	dci_save_external_id($params, $server_id);
	SetServerParam($server_id, $params);

	$dci_ip = "0.0.0.0";
	$wait_time = 0;

	if ($params["configoption4"] == "on")
		$xpath_expr = "/doc/elem[id='" . $server_id . "' and not(operation_failed)]";
	else
		$xpath_expr = "/doc/elem[id='" . $server_id . "' and not(install_in_progress) and not(operation_failed)]";

	//TODO Сделать вейтером
	while (1) {
		$dci_list = $server->AuthInfoRequest("server");
		$find_dci = $dci_list->xpath($xpath_expr);

		if (count($find_dci) == 0) {
		} else {
			$dci_ip = $find_dci[0]->ip;
			break;
		}

		$wait_time = $wait_time + 30;
		sleep(30);

		if ($wait_time > 7200) {
			if (!empty(dci_set_server_domain($params, $server_id, "free.ds")))
				logActivity("DCImanager. Can not reset domain for server " . $server_id);

			return "Can not install server!";
		}
	}

	DB::table('tblhosting')->where('id', $params["serviceid"])->update(['dedicatedip' => $dci_ip]);

	dci_set_server_owner($params, $server_id, $user_id);
	dci_set_server_domain($params, $server_id, $params["domain"]);

	return "success";
}

function dcimanager_TerminateAccount($params)
{
	global $op;
	$op = "terminate";
	$server = new Server($params);

	$id = dci_get_external_id($params);
	if (empty($id)) return "Unknown server!";

	//Разблокируем сервер
	dci_process_operation("server.enable", $params);
	$obj_en = [
		"func" => "server",
		"filter" => [
			"id" => $id,
			"disabled/" => "FALSE",
		]
	];
	if (!OperationWaiter("HasItems", $obj_en, $params, SMALL_TIMER))
		return "The attempt to enable the server " . $id . " failed.";

	//Ожидаем включения всех подключений сервера
	$obj_conn = [
		"func" => "server.connection",
		"param" => ["elid" => $id],
		"filter" => ["func_in_progress/" => "TRUE"]
	];
	if (!OperationWaiter("NoItems", $obj_conn, $params, MEDIUM_TIMER))
		return "The attempt to enable the server " . $id . " failed. Cause func_in_progress.";

	//Отключаем сервер
	dci_process_operation("server.poweroff", $params);
	$obj_off = [
		"func" => "server",
		"filter" => [
			"id" => $id,
			"poweroff or powererror/" => "TRUE",
		]
	];
	if (!OperationWaiter("HasItems", $obj_off, $params, SMALL_TIMER))
		return "The attempt to poweroff the server " . $id . " failed.";

	$server_list = $server->AuthInfoRequest("server");
	$main_ip_x = $server_list->xpath("/doc/elem[id='" . $id . "']");
	$main_ip = $main_ip_x[0]->ip;
	if ($main_ip == "") return "Can not get main ip!";

	$server_ip_list = $server->AuthInfoRequest("iplist", ["elid" => $id]);
	$ip_list = $server_ip_list->xpath("//elem[(not(type) or type != 'group') and ip != '" . $main_ip . "']/id");

	foreach ($ip_list as $ip_id) {
		$server->AuthInfoRequest("iplist.delete", ["elid" => $ip_id, "plid" => $id]);
	}

	if ($server->errorCheck(dci_set_server_owner($params, $id, "no_owner")) != "")
		return "Can not set owner";

	if (!empty(dci_set_server_domain($params, $id, "free.ds")))
		logActivity("DCImanager. Can not reset domain for server " . $id);

	return "success";
}

function dcimanager_SuspendAccount($params)
{
	global $op;
	$op = "suspend";
	return dci_process_operation("server.disable", $params);
}

function dcimanager_UnsuspendAccount($params)
{
	global $op;
	$op = "unsuspend";
	return dci_process_operation("server.enable", $params);
}

function dcimanager_ChangePackage($params)
{
	return "Error: Not supported!";
}

function dcimanager_ClientArea($params)
{
	if ($_POST["process"] == "true") {
		$auth = dcimanager_ServiceSingleSignOn($params);
		header("Location: " . $auth["redirectTo"]);
		exit;
	}
}

function dcimanager_ClientAreaCustomButtonArray()
{
	return [
		"Reboot Server" => "reboot",
		"Power off Server" => "poweroff",
		"Power on Server" => "poweron",
		"Server network off" => "networkoff",
		"Server network on" => "networkon",
		"Reinstall" => "reinstall",
	];
}

function dcimanager_AdminCustomButtonArray()
{
	return [
		"Reboot Server" => "m_reboot",
		"Power off Server" => "m_poweroff",
		"Power on Server" => "poweron",
		"Server network off" => "networkoff",
		"Server network on" => "networkon",
	];
}

function dcimanager_ServiceSingleSignOn(array $params)
{
	global $op;
	$op = "ServiceSingleSignOn";

	$whmcs_user = $params["username"];
	if (empty($whmcs_user)) {
		return ['success' => false, 'errorMsg' => "user is empty"];
	}

	try {
		$key = strtolower(dcimanager_generate_random_string(32));
		$server = new Server($params);
		$newkey = $server->AuthInfoRequest("session.newkey", ["username" => $params["username"], "key" => $key]);

		$error = $server->errorCheck($newkey);
		if (!empty($error)) {
			logActivity("ServiceSingleSignOn error: " . $error);
			return ['success' => false, 'errorMsg' => $error];
		}

		return [
			'success' => true,
			'redirectTo' => "https://" . $params["serverip"] . "/dcimgr?func=auth&username=" . $params["username"] . "&key=" . $key,
		];
	} catch (Exception $e) {
		return ['success' => false, 'errorMsg' => "Error"];
	}
}

function dcimanager_AdminSingleSignOn(array $params)
{
	global $op;
	$op = "AdminSingleSignOn";

	$server_ip = $params["serverip"];
	$server_username = $params["serverusername"];
	try {
		$key = strtolower(dcimanager_generate_random_string(32));
		$server = new Server($params);
		$newkey = $server->AuthInfoRequest("session.newkey", ["username" => $server_username, "key" => $key]);

		$error = $server->errorCheck($newkey);
		if (!empty($error)) {
			logActivity("AdminSingleSignOn error: " . $error);
			return array(
				'success' => false,
				'errorMsg' => $error,
			);
		}

		return array(
			'success' => true,
			'redirectTo' => "https://" . $server_ip . "/dcimgr?checkcookie=no&func=auth&username=" . $server_username . "&key=" . $key,
		);
	} catch (Exception $e) {
		return array(
			'success' => false,
			'errorMsg' => $e->getMessage(),
		);
	}
}

function dcimanager_UsageUpdate($params)
{
	global $op;
	$op = "usage";

	$server = new Server($params);
	$traffic_data = $server->AuthInfoRequest("trafficburstable");

	$result = DB::table('tblhosting')
		->select('tblhosting.id', 'mod_ispsystem.external_id')
		->join('mod_ispsystem', 'mod_ispsystem.serviceid', '=', 'tblhosting.id')
		->where('tblhosting.server', '=', $params['serverid'])
		->whereNotNull('mod_ispsystem.external_id')
		->get();

	foreach ($result as $data) {
		logActivity("DCI" . $data->id);
		$burst = $traffic_data->xpath("/doc/reportdata//elem[id='" . $data->external_id . "']/burst/text()");

		DB::table('tblhosting')
			->where('id', $data->id)
			->update([
				'bwusage' => $burst[0],
				'lastupdate' => DateTime::now()
			]);
	}
}
