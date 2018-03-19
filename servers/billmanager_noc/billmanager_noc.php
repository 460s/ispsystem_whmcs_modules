<?php
/*
 *  Module Version: 7.1.0
 */

if (!defined("WHMCS")) {
	die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule as DB;

// Поиск и формирование ошибки
function billmanager_noc_find_error($xml) {
	$error = "";

	if ($xml->error) {
		$error = $xml->error["type"].":".$xml->error->msg;
	}

	return $error;
}

// Найти внешний id для дицензии
function billmanager_noc_get_external_id($params) {
	$result = DB::table('ispsystem_noc')
		->select('licenseid')
		->where([
		    ['serviceid', '=', $params["serviceid"]],
		    ['licensetype', '=', (array_key_exists("addon_change", $params)) ? 1 : 0],
		])
		->first();
	return $result ? $result->licenseid : "";
}

// Кастомфилды никак не привязаны к услуге напрямую. Менять можно только по порядковому номеру
function billmanager_noc_save_customfield($params,$num,$val){
	// Найдем в какое поле его записать
	$custom_field = DB::table('tblcustomfieldsvalues')
		->select('fieldid')
		->where('relid', $params["serviceid"])
		->skip($num-1)
		->first();

	//пишем
	DB::table('tblcustomfieldsvalues')
		->where([
			['fieldid', '=', $custom_field->fieldid],
			['relid', '=', $params["serviceid"]],
		])
		->update(['value' => $val]);
}

// Запрос и сохранение ключа лицензии
function billmanager_noc_get_param($params,$license_id){
	// Запросим ключ лицензии
	$lickey = "";

	while ($lickey == "") {
		$param_request = billmanager_noc_api_request($params["serverhostname"], $params["serverusername"], $params["serverpassword"], 'soft.edit', array("elid" => $license_id));
		$error = billmanager_noc_find_error($param_request);
		if ($error != "") return array("answer" => $error);

		$lickey_xml = $param_request->xpath("/doc/lickey");
		$lickey = $lickey_xml[0];
		logModuleCall("billmanager_noc", "lickey_req", $lickey);
	}
	// Сохраним ключ лицензии
	if (!array_key_exists("addon_change", $params)) billmanager_noc_save_customfield($params, 3, $lickey);

	$duedate_xml = $param_request->xpath("/doc/expiredate");
	return array("answer" => "success","duedate" => $duedate_xml[0]);

}

// API запросы в биллинг
function billmanager_noc_api_request($link, $username, $password, $func, $param) {
	global $op;

	$default_xml_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc/>\n";
	$default_xml_error_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc><error type=\"curl\"/></doc>\n";

	$postfields = array("out" => "xml", "func" => (string)$func, "authinfo" => (string)$username.":".(string)$password, );
	$options = array ('CURLOPT_TIMEOUT' => '60');
	foreach ($param as $key => &$value) {
		$value = (string)$value;
	}

	$response = curlCall($link, array_merge($postfields, $param), $options);

	logModuleCall("billmanager_noc:".$func, $op, array_merge($postfields, $param), $response, $response, array ($password));

	$out = simplexml_load_string($default_xml_string);

	try {
		$out = new SimpleXMLElement($response);
	} catch (Exception $e) {
		$out = simplexml_load_string($default_xml_error_string);
		$out->error->addChild("msg", $e->getMessage());
	}

	return $out;
}

// Заказ лицензии в ISPsystem
function billmanager_noc_LicenseOrder($params){
	// Параметры заказа
	$order_params = array("pricelist" => $params["configoption1"]
		,"period" => 1
		,"pid" => $params["serviceid"]
		,"ip" => $params["customfields"]["ip"]
		,"licname" => $params["customfields"]["name"]
		,"project" => 1
		,"sok" => "ok"
		,"skipbasket" => "on"
		,"remoteid" => 'l'.$params["serviceid"]
		,"clicked_button" => "finish"
	);
	// Если аддон, то remoteid другой
	if (array_key_exists("addon_change", $params)) $order_params['remoteid'] = 'a'.$params["serviceid"];

	$addon_value = 0;

	if ($params["configoption2"] != '') {
		$addon_value = ($params["configoptions"]["Additional nodes in cluster"]);
		// Если это Cloud - добавить 5 серверов к заказу. К остальным + 1
		$additional_servers = 1;
		if ($params["configoption2"] == '3889') $additional_servers = 5;
		$order_params["addon_".$params["configoption2"]] = $additional_servers + $addon_value;
	}

	// Пробуем заказать лицензию
	$order_license = billmanager_noc_api_request($params["serverhostname"], $params["serverusername"], $params["serverpassword"], "soft.order.param", $order_params);

	$error = billmanager_noc_find_error($order_license);
	if ($error != "") return $error;

	$license_id = $order_license->xpath("/doc/id");

	// Запросим параметры лицензии
	$duedate_req = billmanager_noc_get_param($params,$license_id[0]);
	if ($duedate_req["answer"] != 'success') return $duedate_req["answer"];

	$newparams = array(	"licenseid" => $license_id[0]
		,"licensetype" => 0
		,"serviceid" => $params["serviceid"]
		,"duedate" => $duedate_req['duedate']
		,"servicepackage" => $params["configoption1"]
		,"serverid" => $params["serverid"]
	);

	if ($params["configoption2"] != '') $newparams["serviceaddon"] = $addon_value;

	// Если аддон
	if (array_key_exists("addon_change", $params)) $newparams['licensetype'] = 1;

	// Сохраним
	DB::table('ispsystem_noc')->insert($newparams);

	return "success";
}

// Изменение параметров в ISPsystem
function billmanager_noc_LicenseEdit($params,$license_id,$ext_id){
	$new_param = array("elid" => $license_id
		,"licname" => $params["customfields"]["name"]
		,"ip" => $params["customfields"]["ip"]
		,"remoteid" => 'l'.$ext_id
		,"sok" => "ok"
	);
	// Если аддон, то remoteid другой
	if (array_key_exists("addon_change", $params)) $new_param['remoteid'] = 'a'.$ext_id;

	$update_request = billmanager_noc_api_request($params["serverhostname"],$params["serverusername"],$params["serverpassword"],'soft.edit',$new_param	);
	$error = billmanager_noc_find_error($update_request);
	if ($error != "") return $error;

	return "success";
}

// Продление лицензии в ISPsystem
function billmanager_noc_LicenseProlong($params,$license_id){
	$update_request = billmanager_noc_api_request($params["serverhostname"],$params["serverusername"],$params["serverpassword"],'service.prolong',
		array("elid" => $license_id
		,"period" => 1
		,"sok" => "ok"
		)
	);
	$error = billmanager_noc_find_error($update_request);
	if ($error != "") return array("answer" => $error);

	$duedate = $update_request->xpath("/doc/doc/newexpiredate");
	return array("answer" => "success", "duedate" => $duedate[0]);
}

// Остановка лицензии  в ISPsystem
function billmanager_noc_LicenseSuspend($params,$license_id){
	$suspend_request = billmanager_noc_api_request($params["serverhostname"],$params["serverusername"],$params["serverpassword"],'soft.suspend',array("elid"=>$license_id));
	$error = billmanager_noc_find_error($suspend_request);
	if ($error != "") return $error;

	// Удалим ключ активации
	if (!array_key_exists("addon_change", $params)) billmanager_noc_save_customfield($params, 3, '');

	return 'success';
}

// Включении лицензии  в ISPsystem
function billmanager_noc_LicenseUnSuspend($params,$license_id){
	$suspend_request = billmanager_noc_api_request($params["serverhostname"],$params["serverusername"],$params["serverpassword"],'soft.resume',array("elid"=>$license_id));
	$error = billmanager_noc_find_error($suspend_request);
	if ($error != "") return $error;

	return 'success';
}

/*
 * -------------------------------- Функции WHMCS
*/

// Название модуля
function billmanager_noc_MetaData(){
    return [
        'DisplayName' => 'BILLmanager NOC',
        'AdminSingleSignOnLabel' => 'Login to BILLmanager',
    ];
}

// Заказ лицензии
function billmanager_noc_CreateAccount($params){
	global $op;
	$op = "create";
	$answer = 'success';

	//configoption1 - id тарифа в ISPsystem
	$arr_param = [
		['serviceid', NULL],
		['servicepackage', '=', $params["configoption1"]],
	];

	//configoption2 - id дополнения в ISPsystem
	if ($params["configoption2"] != '')
		array_push($arr_param , ['serviceaddon', '=', $params["configoptions"]["Additional nodes in cluster"]]);

	$result = DB::table('ispsystem_noc')->select('id', 'licenseid', 'duedate')->where($arr_param)->get();

	if ($result) { // Есть свободная
		$use_old_license = false;
		foreach ($result as $data) {
			// Запросим измененение параметров в биллинге ISPsystem
			$answer = billmanager_noc_LicenseEdit($params,$data->licenseid,$params['serviceid']);
			if ($answer == 'success') { //Если дали поменять лицензию
				$use_old_license = true;
			} else {
				continue; // Если нет - пробуем следующую
			}
			$timezone = date_default_timezone_get(); // Сохраним текущую временную зону
			date_default_timezone_set("UTC"); // Сдвинем в UTC
			$today = date("Y-m-d");
			$duedate = $data->duedate;
			if ($today >= $duedate) { // Если текущая дата больше или равна expiredate
				date_default_timezone_set($timezone); // Вернем обратно как было
				// Продлить лицензию
				$prolong_answer = billmanager_noc_LicenseProlong($params,$data->licenseid);
				if ($prolong_answer["answer"] != 'success') {  // Если не дали продлить по какой-то причине, то не привязываем
					$use_old_license = false;
					logModuleCall("billmanager_noc", "license_prolong", $prolong_answer["answer"]);
				}
				$duedate = $prolong_answer["duedate"];
			}else{ //Если лицензия не перешагнула duedate - нужно её включить
				date_default_timezone_set($timezone); // Вернем обратно как было
				// Включение лицензии
				$answer = billmanager_noc_LicenseUnSuspend($params,$data->licenseid);
				if ($answer != "success") return $answer;

				// Запросим ключ лицензии
				$key_answer = billmanager_noc_get_param($params,$data->licenseid);
				if ($key_answer['answer'] != 'success') return $key_answer['answer'];
			}

			if ($use_old_license){
				DB::table('ispsystem_noc')
					->where('id', $data->id)
					->update([
						'licensetype' => (array_key_exists("addon_change", $params)) ? 1 : 0,
						'serviceid' => $params["serviceid"],
						'duedate' => $duedate
					]);
				break; // Привязали лицензию. Заканчиваем
			}
		}
		// Если не использовали старую лицензию, то нужно заказать новую
		if (!$use_old_license) $answer = billmanager_noc_LicenseOrder($params);
	} else {
		// Не нашли свободную лицензию. Заказываем новую
		$answer = billmanager_noc_LicenseOrder($params);
	}
	return $answer;
}

// Остановка лицензии
function billmanager_noc_SuspendAccount($params){
	global $op;
	$op = "suspend";

	// Остановим лицензию
	$answer = billmanager_noc_LicenseSuspend($params,billmanager_noc_get_external_id($params));
	if ($answer != 'success') return $answer;

	return 'success';
}

// Включение после остановки
function billmanager_noc_UnSuspendAccount($params){
	global $op;
	$op = "unsuspend";

	$license_id = billmanager_noc_get_external_id($params);

	// Проверим expiredate
	$result = DB::table('ispsystem_noc')->select('duedate')->where('licenseid', $license_id)->first();

	$timezone = date_default_timezone_get(); // Сохраним текущую временную зону
	date_default_timezone_set("UTC"); // Сдвинем в UTC
	$today = date("Y-m-d");
	$duedate = $result->duedate;
	if ($today >= $duedate) { // Если текущая дата больше или равна expiredate
		date_default_timezone_set($timezone); // Вернем обратно как было
		$prolong_answer = billmanager_noc_LicenseProlong($params,$license_id);
		if ($prolong_answer["answer"] != 'success') return $prolong_answer["answer"];

		// Обновим дату в базе
                DB::table('ispsystem_noc')->where('licenseid', $license_id)->update(['duedate' => $prolong_answer['duedate']]);
                DB::table('tblhosting')->where('id', $params["serviceid"])
                    ->update([
                        'nextduedate' => $prolong_answer['duedate'],
                        'nextinvoicedate' => $prolong_answer['duedate'],
                    ]);

		// Запросим ключ лицензии
		$answer = billmanager_noc_get_param($params,$license_id);
		if ($answer['answer'] != 'success') return $answer['answer'];
	} else {
		date_default_timezone_set($timezone); // Вернем обратно как было
		// Включение лицензии
		$answer = billmanager_noc_LicenseUnSuspend($params,$license_id);
		if ($answer != "success") return $answer;

		// Сменим имя, ip, remoteid
		$answer = billmanager_noc_LicenseEdit($params,$license_id,$params['serviceid']);
		if ($answer != 'success') return $answer;

		// Запросим ключ лицензии
		$answer = billmanager_noc_get_param($params,$license_id);
		if ($answer['answer'] != 'success') return $answer['answer'];
	}

	return 'success';
}

// Удаление
function billmanager_noc_TerminateAccount($params){
	global $op;
	$op = "terminate";

	$license_id = billmanager_noc_get_external_id($params);

	// Остановим лицензию
	$answer = billmanager_noc_LicenseSuspend($params,$license_id);
	if ($answer != 'success') return $answer;

	// Сменим имя, ip, remoteid
	$params["customfields"]["name"] = 'free.lic';
	$params["customfields"]["ip"] = '0.0.0.0';
	$answer = billmanager_noc_LicenseEdit($params,$license_id,'');
	if ($answer != 'success') return $answer;

	// Удалим привязку из базы
	DB::table('ispsystem_noc')
	->where([
	    ['serviceid', '=', $params["serviceid"]],
	    ['licensetype', '=', (array_key_exists("addon_change", $params)) ? 1 : 0]
	])
	->update(['serviceid' => NULL]);


	return 'success';
}

// Продление
function billmanager_noc_Renew($params){
	// Продлевать будем по крону, поэтому здесь просто выйдем

	return 'success';
}

// Смена тарифа
function billmanager_noc_ChangePackage(){
	return "Error: Not supported!";
}

// Изменить параметры под админом
function billmanager_noc_Update($params){
	global $op;
	$op = "update";

	$license_id = billmanager_noc_get_external_id($params);
	$answer = billmanager_noc_LicenseEdit($params,$license_id,$params['serviceid']);
	if ($answer != "success") return $answer;

	return "success";
}

// Генерация нового ключа лицензии
function billmanager_noc_Newkey($params){
	global $op;
	$op = "newkey";

	$license_id = billmanager_noc_get_external_id($params);

	// Сгенерировать ключ лицензии

	$newkey_request = billmanager_noc_api_request($params["serverhostname"],$params["serverusername"],$params["serverpassword"],'soft.edit',
		array("elid" => $license_id
		,"clicked_button" => "newkey"
		,"sok" => "ok"
		)
	);
	$error = billmanager_noc_find_error($newkey_request);
	if ($error != "") return $error;

	// Сохраним ключ лицензии
	$lickey_xml = $newkey_request->xpath("/doc/lickey");
	billmanager_noc_save_customfield($params, 3, $lickey_xml[0]);

	return "success";
}

// Дополнительные кнопки для клиента
function billmanager_noc_ClientAreaCustomButtonArray() {
	$button_array = array("Generate a new key" => "Newkey");
	return $button_array;
}

// Дополнительные кнопки для админа
function billmanager_noc_AdminCustomButtonArray() {
	$button_array = array(	"Change params" => "Update"
	, "Generate a new key" => "Newkey");

	return $button_array;
}

function billmanager_noc_AdminSingleSignOn($params){
    global $op;
    $op = "auth";

    $server_ip = $params["serverhostname"];
    $server_username = $params["serverusername"];
    $server_password = $params["serverpassword"];

    try {
        $key = md5(time()).md5($params["username"]);
        $newkey = billmanager_noc_api_request($server_ip, $server_username, $server_password, "session.newkey", ["username" => $server_username, "key" => $key]);
        $error = billmanager_noc_find_error($newkey);

        if (!empty($error)) {
             return  ['success' => false, 'errorMsg' => $error];
        }

        return [
            'success' => true,
            'redirectTo' => "https://my.ispsystem.com/billmgr?checkcookie=no&func=auth&username=".$server_username."&key=".$key,
        ];
    } catch (Exception $e) {
         return [
            'success' => false,
            'errorMsg' => $e->getMessage(),
        ];
    }
}

?>