<?php
use WHMCS\Database\Capsule as DB;
use WHMCS\Service\Service as SERV;

define("SMALL_TIMER", "10");
define("MEDIUM_TIMER", "20");

/**
 * Проверяет наличие элемента в списке серверов,
 * соответствующего заданному фильтру.
 * @param $obj array массив параметров для xpath
 * @param $params array массив параметров текущей услуги
 * @return bool
 */
function HasItems(&$obj, &$params)
{
	$server = new Server($params);
	$serverXml = $server->AuthInfoRequest($obj["func"], !empty($obj["param"]) ? $obj["param"] : []);

	$xp = "/doc/elem";
	foreach ($obj["filter"] as $key => $val) {
		if (substr($key, -1) === "/")
			$fstr .= "(" . ($val === "TRUE" ? "" : "not") . "(" . substr($key, 0, -1) . "))";
		else
			$fstr .= "(" . $key . "='" . $val . "')";
		if (next($obj["filter"])) $fstr .= " and ";
	}
	$xp .= "[" . $fstr . "]";
	$findItem = $serverXml->xpath($xp);

	logModuleCall("dcimanager", "xpath", $xp, $findItem, $findItem);

	return count($findItem) > 0;
}

function NoItems(&$obj, &$params){
	if (HasItems($obj, $params))
		return false;
	return true;
}
/**
 * Вейтер операций. Повторяет операцию $num раз пока не получит true
 * @param $func string функция для вызова в цикле
 * @param $filter array Параметры передаваемые в функцию
 * @param $param string Параметры передаваемые в функцию
 * @param $num int Количество повторений функции
 * @return bool Возвращает true если получил от $func true и false если
 * провел все итерации и не получил true
 */
function OperationWaiter($func, &$filter, &$param, $num)
{
	while ($num) {
		if ($func($filter, $param))
			return true;
		sleep(5);
		$num--;
	}
	return false;
}

/*
 * Получение и запись параметров сервера в mod_ispsystem
 * Параметры: наклейка, ip адрес ipmi, порт коммутатора
 */
function SetServerParam($elid , $params)
{
	$server = new Server($params);

	$param = ["elid" => $elid];
	$editXml = $server->AuthInfoRequest("server.edit", $param);
	$label = $editXml->xpath("/doc/name/text()");
	$mac = $editXml->xpath("/doc/mac/text()");

	$connectXml = $server->AuthInfoRequest("server.connection", $param);
	$switchID = $connectXml->xpath("/doc/elem[(type='Switch')][1]/id/text()");
	$ipmiID = $connectXml->xpath("/doc/elem[(type='IPMI')][1]/id/text()");

	$param = ["plid" => $elid, "elid" => $switchID[0]];
	$editSwitchXml = $server->AuthInfoRequest("server.connection.edit", $param);
	$switchPort = $editSwitchXml->xpath("/doc/port/text()");

	$param = ["plid" => $elid, "elid" => $ipmiID[0]];
	$editIpmiXml = $server->AuthInfoRequest("server.connection.edit", $param);
	$ipmiIP = $editIpmiXml->xpath("/doc/ipmiip/text()");

	DB::table('mod_ispsystem')->where('external_id', $elid)->update([
		'label' => $label[0],
		'ipmi_ip' => $ipmiIP[0],
		'switch_port' => $switchPort[0],
		'mac' => $mac[0]
	]);

}

/*
 * Получение параметров из mod_ispsystem
 * Параметры: наклейка, ip адрес ipmi, порт коммутатора
 */
function GetServerParam($serviceid)
{
	try {
		return DB::table('mod_ispsystem')
			->select('label', 'ipmi_ip', 'switch_port', 'mac')
			->where('serviceid', $serviceid)
			->first();
	} catch (Exception $e) {
		return (object)['mac' => 'Please visit the add-ons page, it will update the DCImgr module.'];
	}
}

/*
 * Добавление IP адресов к серверу
 * @var $count Количество Ip адресов
 * @var $type Тип адресов
 * @var $serverId Id сервера в DCImgr
 * @var $params Массив параметров WHMCS
 */
function AddIpToServer($count, $type, &$serverId, &$params)
{
	$server = new Server($params);

	while ($count > 0) {
		$new_ip_param = array(
			"plid" => $serverId,
			"domain" => $params["domain"],
			"sok" => "ok",
			"iptype" => empty($params["configoption6"]) ? "public" : $params["configoption6"],
			"ip" => "",
			"family" => $type,
		);

		$ip_add = $server->AuthInfoRequest("iplist.edit", $new_ip_param);
		$ip_list .= $ip_add->ip . "\n";
		$count--;
	}

	DB::table('tblhosting')
		->where('id', $params["serviceid"])
		->update(['assignedips' => DB::raw("CONCAT(assignedips,'".$ip_list."')")]);
}

/*
 * Проверка существования заказов для данного модуля
 * @description Проверяет есть ли активные заказы у этого пользователя для
 * этого модуля. Проверяет есть ли несколько машин в одном заказе
 * @var $params Массив параметров WHMCS
 */
function CheckSimilarServers(&$params)
{
	$user_data = DB::table('tblhosting')
		->join('tblorders', 'tblhosting.orderid', '=', 'tblorders.id')
		->select('username', 'password')
		->where([
			['tblhosting.userid', $params["userid"]],
			['tblhosting.server', $params["serverid"]],
			['tblorders.status', "Active"],
		])
		->first();
	if ($user_data) return $user_data;

	$service = SERV::find($params["serviceid"]);
	$user_data = DB::table('tblhosting')
		->select('username', 'password')
		->where([
			['userid', $params["userid"]],
			['orderid', $service->orderid],
			['username', '<>', ''],
		])
		->first();

	return $user_data;
}

/*
 * Генерация доменного имени на основе заданного шаблона
 * @var $params Массив параметров WHMCS
 */
function GenerateDomain(&$params)
{
	$domain = $params["configoption5"];
	$domain = str_replace("@ID@", $params["serviceid"], $domain);
	$domain = str_replace("@DOMAIN@", $params["domain"], $domain);

	$num = "";
	do{
		$domain .= $num;
		$query = DB::table('tblhosting')
			->select('username')
			->where([
				['username', $domain],
				['id', '!=', $params["serviceid"]]
			])->get();
		$num++;
	}while(count($query) > 0);
	DB::table('tblhosting')->where('id', $params["serviceid"])->update(['domain' => $domain]);

	return $domain;
}

/*
 * Шифровка пароля пароля на основании имени администратора
 * @var $params Массив параметров WHMCS
 * @var $func Имя функции для localAPI
 */
function EncriptPassword(&$pass, $func = 'EncryptPassword')
{
	$admin_data = DB::table('tbladmins')
		->leftJoin('tbladminperms', 'tbladmins.roleid', '=', 'tbladminperms.roleid')
		->where([
			['tbladmins.disabled', '=', 0],
			['tbladminperms.permid', '=', 81],
		])
		->select('tbladmins.username')
		->first();
	$pass_data = localAPI($func, array("password2" => $pass), $admin_data->username);

	return $pass_data['result'] === 'success' ? $pass_data['password'] : "";
}

/*
 * Дешифровка пароля пароля на основании имени администратора
 * @var $params Массив параметров WHMCS *
 */
function DecryptPassword(&$pass)
{
	return EncriptPassword($pass, 'DecryptPassword');
}

/**
 * Выполнение запросов к панели от имени польщователя.
 * Генерируется ключ сессии для пользователя под администратором.
 * С ключем получаем строку авторизации для пользователя, затем вызываем функцию
 * @param $params array Массив параметров WHMCS
 * @param $func string вызываемый в панели
 * @return string
 */
function dci_process_client_operation($func, &$params)
{
	if (isset($_POST["abort"])) return "Operation aborted by user";
	$id = dci_get_external_id($params);
	if ($id == "") {
		return "Unknown server!";
	}

	$server = new Server($params);
	$key = strtolower(dcimanager_generate_random_string(32));
	$newkey = $server->AuthInfoRequest("session.newkey",["username" => $params["username"], "key" => $key]);

	$error = $server->errorCheck($newkey);
	if (!empty($error)) {
		logActivity($func . " error: " . $error);
		return $error;
	}

	$auth = $server->GetAuth($key);
	$result = $server->AuthRequest($func, $auth, ["elid" => $id]);
	$error = $server->errorCheck($result);

	return !empty($error) ? $error : "success";
}

/**
 * Выполнение запросов к панели от имени администратора.
 * @param $params array Массив параметров WHMCS
 * @param $func string вызываемый в панели
 * @return string
 */
function dci_process_operation($func, $params)
{
	$id = dci_get_external_id($params);
	if (empty($id)) return "Unknown server!";

	$server = new Server($params);
	$result = $server->AuthInfoRequest($func, ["elid" => $id]);
	$error = $server->errorCheck($result);

	return !empty($error) ? $error : "success";
}

function dci_get_external_id($params)
{
	$result = DB::table('mod_ispsystem')
		->select('external_id')
		->where([
			['serviceid', $params["serviceid"]],
			['external_id', '<>', ''],
		])
		->first();

	if ($result) return $result->external_id;

	return "";
}

function dci_save_external_id($params, $external_id)
{
	$result = DB::table('mod_ispsystem')->select('serviceid')->where('serviceid', $params["serviceid"])->first();

	if ($result) {
		DB::table('mod_ispsystem')->where('serviceid', $params["serviceid"])->update(['external_id' => $external_id]);
	} else {
		DB::table('mod_ispsystem')->insert(['external_id' => $external_id, 'serviceid' => $params["serviceid"]]);
	}
}

function dci_set_server_owner($params, $id, $user_id)
{
	$server = new Server($params);
	$server_props = $server->AuthInfoRequest("server.edit", ["elid" => $id]);
	return $server->AuthInfoRequest("server.edit",
		["elid" => $id,
			"sok" => "ok",
			"mac" => $server_props->mac,
			"name" => $server_props->name,
			"notes" => $server_props->notes,
			"type" => $server_props->type,
			"owner" => $user_id
		]);
}

function dci_set_server_domain($params, $id, $domain)
{
	$server = new Server($params);
	$server_ip_list = $server->AuthInfoRequest("iplist", ["elid" => $id]);
	$ip_list = $server_ip_list->xpath("//elem[not(type) or type != 'group']/id");

	$error = "";
	foreach ($ip_list as $ip_id) {
		$out = $server->AuthInfoRequest("iplist.edit",
			["elid" => (string)$ip_id,
				"sok" => "ok",
				"plid" => $id,
				"domain" => $domain]);
		$error.= $server->errorCheck($out);
	}

	return $error;
}

function dcimanager_generate_random_string($length = 12)
{
	$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
	$randomString = '';
	for ($i = 0; $i < $length; $i++) {
		$randomString .= $characters[rand(0, strlen($characters) - 1)];
	}
	return $randomString;
}

function dcimanager_reinstall($params)
{
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

		$server = new Server($params);
		$key = strtolower(dcimanager_generate_random_string(32));
		$newkey = $server->AuthInfoRequest("session.newkey", ["username" => $params["username"], "key" => $key]);

		$error = $server->errorCheck($newkey);
		if (!empty($error)) {
			logActivity($func . " error: " . $error);
			return $error;
		}

		$auth = $server->GetAuth($key);
		$result = $server->AuthRequest("server.operations", $auth, ["sok" => "ok",
			"elid" => (string)dci_get_external_id($params),
			"operation" => "ostemplate",
			"ostemplate" => $os,
			"passwd" => $_POST["passwd"],
			"confirm" => $_POST["passwd"],
			"checkpasswd" => $_POST["passwd"]]);
		$error = $server->errorCheck($result);

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
function dcimanager_m_reboot($params)
{
	global $op;
	$op = "reboot";
	return dci_process_client_operation("server.reboot", $params);
}

function dcimanager_reboot($params)
{
	if (isset($_POST["a"]))
		return dcimanager_m_reboot($params);

	return [
		'templatefile' => 'alert',
		'vars' => [
			'action' => 'reboot',
			'description' => 'Reboot Server'
		]
	];
}

function dcimanager_m_poweroff($params)
{
	global $op;
	$op = "stop";
	return dci_process_client_operation("server.poweroff", $params);
}

function dcimanager_poweroff($params)
{
	if (isset($_POST["a"]))
		return dcimanager_m_poweroff($params);

	return [
		'templatefile' => 'alert',
		'vars' => [
			'action' => 'poweroff',
			'description' => 'Stop Server'
		]
	];
}

function dcimanager_poweron($params)
{
	global $op;
	$op = "poweron";
	return dci_process_client_operation("server.poweron", $params);
}

function dcimanager_networkoff($params)
{
	global $op;
	$op = "networkoff";

	$id = dci_get_external_id($params);
	if (empty($id)) return "Unknown server!";

	$server = new Server($params);
	$server_list = $server->AuthInfoRequest("server.connection", ["elid" => $id]);

	foreach ($server_list->xpath("/doc/elem[type='Switch']") as $elem) {
		$server->AuthInfoRequest("server.connection.off", ["plid" => $id, "elid" => $elem->id]);
	}

	return "success";
}

function dcimanager_networkon($params)
{
	global $op;
	$op = "networkon";

	$id = dci_get_external_id($params);
	if (empty($id)) return "Unknown server!";

	$server = new Server($params);
	$servers = $server->AuthInfoRequest("server.edit", ["elid" => $id]);
	if ($servers->xpath("/doc[(disabled='on')]")) return "Server is blocked";

	$server_list = $server->AuthInfoRequest("server.connection", ["elid" => $id]);

	foreach ($server_list->xpath("/doc/elem[type='Switch']") as $elem) {
		$server->AuthInfoRequest("server.connection.on", ["plid" => $id, "elid" => $elem->id]);
	}

	return "success";
}
