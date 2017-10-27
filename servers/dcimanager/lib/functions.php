<?php
use WHMCS\Database\Capsule as DB;
use WHMCS\Service\Service as SERV;

/*
 * Проверяет наличие элемента в списке серверов,
 * соответствующего заданному фильтру.
 * @var $filter массив параметров для xpath
 * @var $params массив параметров текущей услуги
 */
function HasItems(&$obj, &$params)
{
	$server = new Server($params);
	$serverXml = $server->apiRequest($obj["func"], !empty($obj["param"]) ? $obj["param"] : []);

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
/*
 * Вейтер операций. Повторяет операцию $num раз пока не получит true
 * @out Возвращает true если получил от $func true и false если
 * провел все итерации и не получил true
 * @var $func функция для вызова в цикле
 * @var $filter/$param Параметры передаваемые в функцию
 * @var $num количество вызовов функции
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
	$editXml = $server->apiRequest("server.edit", $param);
	$label = $editXml->xpath("/doc/name/text()");
	$mac = $editXml->xpath("/doc/mac/text()");

	$connectXml = $server->apiRequest("server.connection", $param);
	$switchID = $connectXml->xpath("/doc/elem[(type='Switch')][1]/id/text()");
	$ipmiID = $connectXml->xpath("/doc/elem[(type='IPMI')][1]/id/text()");

	$param = ["plid" => $elid, "elid" => $switchID[0]];
	$editSwitchXml = $server->apiRequest("server.connection.edit", $param);
	$switchPort = $editSwitchXml->xpath("/doc/port/text()");

	$param = ["plid" => $elid, "elid" => $ipmiID[0]];
	$editIpmiXml = $server->apiRequest("server.connection.edit", $param);
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

		$ip_add = $server->apiRequest("iplist.edit", $new_ip_param);
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
function DecryptPassword($pass)
{
	return EncriptPassword($pass, 'DecryptPassword');
}