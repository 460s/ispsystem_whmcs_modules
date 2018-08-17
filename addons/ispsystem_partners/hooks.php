<?php
/*
 *  Module Version: 7.1.0
 */

use WHMCS\Database\Capsule as DB;
define("HOOK_MODULE_NAME",     "hook_ispsystem_partners");

// Проверка - наш ли это аддон
function hook_ispsystem_partners_check ($vars){
    $result = DB::table('ispsystem_noc_addon')->select('id')->where('addonid', $vars["addonid"])->first();
    return $result ? true : false;
}

// Поиск данных от сервера обработки
function hook_ispsystem_partners_find_server($addonid){
    // Найдем сервер обработки
    $result = DB::table('ispsystem_noc_addon')->select('serverid')->where('addonid', $addonid)->first();
    if (!$result) return "error";

    // Найдем адрес, имя\пароль
    $server_data = DB::table('tblservers')->select('id', 'hostname', 'username', 'password')->where('id', $result->serverid)->first();
    if (!$server_data) return "error";

    // Чтобы расшифровать пароль, нужно найти активного админа
    $admin_data = DB::table('tbladmins')
            ->leftJoin('tbladminperms', 'tbladmins.roleid', '=', 'tbladminperms.roleid')
            ->where([
                ['tbladmins.disabled', '=', 0],
                ['tbladminperms.permid', '=', 81],
            ])
            ->select('tbladmins.username')
            ->first();
    if (!$admin_data) return "error";

    // Расшифруем пароль
    $password_req = localAPI('decryptpassword',array("password2" => $server_data->password),$admin_data->username);
    $password = $password_req['password'];

    return [
        "serverhostname" => $server_data->hostname,
        "serverusername" => $server_data->username,
        "serverpassword" => $password,
        "serverid" => $server_data->id
    ];
}

// Заполнение параметров для функций в billmanager_noc
function hook_ispsystem_partners_fill_params($vars){
    $params = array();

    $server = hook_ispsystem_partners_find_server($vars["addonid"]);
    if ($server == 'error') {
        logModuleCall(HOOK_MODULE_NAME, "find_server", "find_server", "Can't find ISPsystem server");
        return "error";
    }
    $params['serverhostname'] = $server['serverhostname'];
    $params['serverusername'] = $server["serverusername"];
    $params['serverpassword'] = $server["serverpassword"];
    $params['serverid'] = $server["serverid"];

    // Id тарифа в ISPsystem
    $result = DB::table('ispsystem_noc_addon')->select('priceid', 'priceaddon')->where('addonid', $vars['addonid'])->first();
    if (!$result) {
        logModuleCall(HOOK_MODULE_NAME, "price_find", "price_find","Can't find addon price");
        return "error";
    }
    $params['configoption1'] = $result->priceid;
    if (!is_null($result->priceaddon) ) {
        $params['configoption2'] = $result->priceaddon;
        $params['configoptions'] = array('Additional nodes in cluster' => 0);
    }

    // Имя и IP лицензии
    $lic_data = DB::table('tblhosting')->select('domain', 'dedicatedip')->where('id', $vars['serviceid'])->first();
    if (!$lic_data) {
        logModuleCall(HOOK_MODULE_NAME, "hosting_find", "hosting_find","Can't find tblhosting domain and ip");
        return "error";
    }
    $params['customfields'] = ['ip' => $lic_data->dedicatedip, 'name' => $lic_data->domain."_addon"];
    $params['serviceid'] = $vars['id'];

    // Установим флаг, что это аддон
    $params['addon_change'] = 'yes';

    return $params;
}

// Переведем addon в Pending, чтобы хостер знал, что что-то не так
function hook_ispsystem_partners_error($id,$message){
    logModuleCall(HOOK_MODULE_NAME, $message['action'], $message['action'], $message['error']);
    DB::table('tblhostingaddons')->where('id', $id)->update(['status' => 'Pending']);
}

// Хелпер для запуска операции
function hook_ispsystem_partners_action($vars,$action){
    if (hook_ispsystem_partners_check($vars)) {
        $mypath = dirname(__FILE__);
		require_once substr($mypath,0,strpos($mypath,'addons/ispsystem_partners'))."servers/billmanager_noc/billmanager_noc.php";


        global $op;
        $op = $action;

        $params = hook_ispsystem_partners_fill_params($vars);
        if ($params == 'error') hook_ispsystem_partners_error($vars['id'],array('action' => $action, 'error' => "Can't fill params"));

        $answer = 'error';

        switch ($action) {
            case 'create' : $answer = billmanager_noc_CreateAccount($params); break;
            case 'suspend' :  $answer = billmanager_noc_SuspendAccount($params); break;
            case 'unsuspend' :  $answer = billmanager_noc_UnSuspendAccount($params); break;
            case 'terminate' : $answer = billmanager_noc_TerminateAccount($params); break;
            case 'admin' : // Если админ перевел в Active
                $result = DB::table('ispsystem_noc')
                    ->select('licenseid')
                    ->where([
                        ['serviceid', '=', $vars['id']],
                        ['licensetype', '=', '1'],
                    ])
                    ->first();
                if ($result) { // Если есть лицензия - включить
                    $answer = billmanager_noc_UnSuspendAccount($params);
                }else{ // Если нет лицензии - заказать
                    $answer = billmanager_noc_CreateAccount($params);
                }
                break;
        }

        if ($answer != 'success') hook_ispsystem_partners_error($vars['id'],array('action' => $action, 'error' => $answer));

    }
}

function hook_ispsystem_partners_addon_cancel(&$data, &$now){
	if ($data->licensetype != 1) return;
	$service_addon_id = $data->serviceid;

	$addon = DB::table('tblhostingaddons')
		->where('id', $service_addon_id)
		->select('addonid','regdate','hostingid')
		->first();
	$addon_id = $addon->addonid;

	$configuration = DB::table('tblmodule_configuration')
		->where([
			['entity_id', '=', $addon_id],
			['setting_name', '=', "configoption2"],
		])
		->select('value')
		->first();

	if (empty($configuration->value)) return;

	$date_now = new DateTime($now);
	$date = new DateTime($addon->regdate);
	$date_cancel =$date->add(new DateInterval('P'.(int)$configuration->value.'M'));
	if ($date_cancel <= $date_now) {
		logModuleCall(HOOK_MODULE_NAME, "find_cancel_lic", "find_cancel_lic","find addon: ".$addon_id.", cancel date: ".$date_cancel->format('Y-m-d'));
		$vars = [
			"id" => $service_addon_id,
			"userid" => 0,
			"serviceid" => $addon->hostingid,
			"addonid" => $addon_id
		];
		hook_ispsystem_partners_action($vars,'terminate');

		DB::table('tblhostingaddons')
			->where('id', $service_addon_id)
			->update([
				'status' => "Terminated",
				'termination_date' => $date_now,
			]);

		return true;
	}
	return false;
}

//
// ---------- Хуки
//

// Заказ лицензии
add_hook('AddonActivation',1,function($vars){ hook_ispsystem_partners_action($vars,'create'); });

// Включение лицензии под админом
add_hook('AddonActivated',1,function($vars){ hook_ispsystem_partners_action($vars,'admin'); });

// Остановка лицензии
add_hook('AddonSuspended',1,function($vars){ hook_ispsystem_partners_action($vars,'suspend'); });

// Включение лицензии
add_hook('AddonUnsuspended',1,function($vars){ hook_ispsystem_partners_action($vars,'unsuspend'); });

// Удаление лицензии
add_hook('AddonTerminated',1,function($vars){ hook_ispsystem_partners_action($vars,'terminate'); });

add_hook('AddonConfigSave',1,function($vars){
	logModuleCall(HOOK_MODULE_NAME, "AddonConfigSave", "AddonConfigSave","AddonConfigSave");
	$addon = DB::table('tbladdons')
		->where('id', $vars['id'])
		->select('module')
		->first();
	if ($addon->module != "billmanager_noc_addon") return;

	$configuration = DB::table('tblmodule_configuration')
		->where([
			['entity_id', '=', $vars['id']],
			['setting_name', '=', "configoption1"],
		])
		->select('value')
		->first();

	switch ($configuration->value) {
		case "ISPmanager 5 Lite":
			$price_id = 3541;
			$addon_id = 0;
			break;
		case "ISPmanager 5 Business":
			$price_id = 4601;
			$addon_id = 4602;
			break;
		case "VMmanager 5 OVZ":
			$price_id = 3651;
			$addon_id = 3698;
			break;
		case "VMmanager 5 KVM":
			$price_id = 3045;
			$addon_id = 3049;
			break;
		case "VMmanager 5 Cloud":
			$price_id = 3887;
			$addon_id = 3889;
			break;
	}

	$isp_addon = DB::table('ispsystem_noc_addon')->select('addonid')->where('addonid', $vars['id'])->first();
	if ($isp_addon) {
		DB::table('ispsystem_noc_addon')
			->where('addonid', $vars['id'])
			->update([
				'priceid' => $price_id,
				'priceaddon' => $addon_id
			]);
	} else {
		$server = DB::table('tblservers')->select('id')->where('type', 'billmanager_noc')->first();
		DB::table('ispsystem_noc_addon')->insert([
			'addonid' => $vars['id'],
			'priceid' => $price_id,
			'serverid' => $server ? $server->id : 0,
			'priceaddon' => $addon_id
		]);
	}
});

// Нужно
// продлить привязанные лицензии
// удалить из базы не привязанные лицензии
add_hook('PreCronJob',1,function(){
    $result = DB::table('ispsystem_noc')->select(DB::raw('id, licenseid, licensetype, serviceid, serverid, (duedate - interval 1 day) duedate, (duedate + interval 1 month) terminatedate'))->get();
    $count_prolonged = 0;
    $count_deleted = 0;
    $timezone = date_default_timezone_get(); // Сохраним текущую временную зону
    date_default_timezone_set("UTC"); // Сдвинем в UTC
    $today = date("Y-m-d");

    // Чтобы расшифровать пароль, нужно найти активного админа (81 - разрешение функции API)
    $admin_data = DB::table('tbladmins')
            ->leftJoin('tbladminperms', 'tbladmins.roleid', '=', 'tbladminperms.roleid')
            ->where([
                ['tbladmins.disabled', '=', 0],
                ['tbladminperms.permid', '=', 81],
            ])
            ->select('tbladmins.username')
            ->first();
    if (!$admin_data) {
        logModuleCall(HOOK_MODULE_NAME, "find_server", "find_server", "Can't find admin with API access");
        return false;
    }

    $mypath = dirname(__FILE__);
	require_once substr($mypath,0,strpos($mypath,'addons/ispsystem_partners'))."servers/billmanager_noc/billmanager_noc.php";

    foreach ($result as $data) {
        if (!is_null($data->serviceid)){
            // Если лицензия привязана
            if ($today >= $data->duedate) {
                // Продлеваем лицензию за день до окончания срока
				if (hook_ispsystem_partners_addon_cancel($data, $today)) {
					continue;
				}
                // Найдем адрес, имя\пароль
                $server_data = DB::table('tblservers')->select('id', 'hostname', 'username', 'password')->where('id', $data->serverid)->first();
                if (!$server_data) {
                    logModuleCall(HOOK_MODULE_NAME, "find_server", "find_server","Can't find ISPsystem server");
                    continue;
                }

                // Расшифруем пароль
                $password_req = localAPI('decryptpassword',array("password2" => $server_data->password),$admin_data->username);

                $params = array();
                $params['serverhostname'] = $server_data->hostname;
                $params['serverusername'] = $server_data->username;
                $params['serverpassword'] = $password_req['password'];
                $params['serverid'] = $server_data->id;

                $answer = billmanager_noc_LicenseProlong($params,$data->licenseid);
                if ($answer["answer"] != 'success') {
                    logModuleCall(HOOK_MODULE_NAME, "prolong", "prolong","Can't prolong license ".$data->licenseid,$answer['answer']);
                    continue;
                }

                // Обновим информацию в базе
                DB::table('ispsystem_noc')->where('id', $data->id)->update(['duedate' => $answer["duedate"]]);
                $count_prolonged++;
            }
        } else {
            // Если лицензия не привязана
            if ($today >= $data->terminatedate) {
                // Если сегодня лицензия удалится
                // Уберем запись из базы
                DB::table('ispsystem_noc')->where('id', '=', $data->id)->delete();
                logModuleCall(HOOK_MODULE_NAME, "delete", "delete","Deleting license from database: ".$data->licenseid);
                $count_deleted++;
            }
        }
    }
    date_default_timezone_set($timezone); // Вернем обратно как было
    logModuleCall(HOOK_MODULE_NAME, "cron", "cron", "Prolonged licenses: ".$count_prolonged.". Deleted licenses: ".$count_deleted);
    return true;
});
