<?php
use WHMCS\Database\Capsule as DB;

/*
 * Проверяет наличие элемента в списке серверов,
 * соответствующего заданному фильтру.
 * @var $filter массив параметров для xpath
 * @var $params массив параметров текущей услуги
 */
function HasItems($filter, $params)
{
	$server = new Server($params);
	$serverXml = $server->apiRequest("server");

	$xp = "/doc/elem";
	foreach ($filter as $key => $val) {
		if (substr($key, -1) === "/")
			$fstr .= "(" . ($val === "TRUE" ? "" : "not") . "(" . substr($key, 0, -1) . "))";
		else
			$fstr .= "(" . $key . "='" . $val . "')";
		if (next($filter)) $fstr .= " and ";
	}
	$xp .= "[" . $fstr . "]";
	$findItem = $serverXml->xpath($xp);

	logModuleCall("dcimanager", "xpath", $xp, $findItem, $findItem);

	return count($findItem) > 0;
}

/*
 * Вейтер операций. Повторяет операцию $num раз пока не получит true
 * @out Возвращает true если получил от $func true и false если
 * провел все итерации и не получил true
 * @var $func функция для вызова в цикле
 * @var $filter/$param Параметры передаваемые в функцию
 * @var $num количество вызовов функции
 */
function OperationWaiter($func, $filter, $param, $num)
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

	$connectXml = $server->apiRequest("server.connection", $param);
	$switchID = $connectXml->xpath("/doc/elem[(type='Switch')][1]/id/text()");
	$ipmiID = $connectXml->xpath("/doc/elem[(type='IPMI')][1]/id/text()");

	$param = ["plid" => $elid, "elid" => $switchID[0]];
	$editSwitchXml = $server->apiRequest("server.connection.edit", $param);
	$switchPort = $editSwitchXml->xpath("/doc/port/text()");

	$param = ["plid" => $elid, "elid" => $ipmiID[0]];
	$editIpmiXml = $server->apiRequest("server.connection.edit", $param);
	$ipmiIP = $editIpmiXml->xpath("/doc/ip/text()");

	DB::table('mod_ispsystem')->where('external_id', $elid)->update([
		'label' => $label[0],
		'ipmi_ip' => $ipmiIP[0],
		'switch_port' => $switchPort[0]
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
			->select('label', 'ipmi_ip', 'switch_port')
			->where('serviceid', $serviceid)
			->first();
	} catch (Exception $e) {
		return (object)['label' => 'Please visit the add-ons page, it will update the DCImgr module.'];
	}
}