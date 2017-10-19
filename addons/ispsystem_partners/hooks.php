<?php
use WHMCS\Database\Capsule as DB;
// Для работы с дополнениями нужно использовать хуки

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
        logModuleCall("hook_ispsystem_partners", "find_server", "Can't find ISPsystem server");
        return "error";
    }
    $params['serverhostname'] = $server['serverhostname'];
    $params['serverusername'] = $server["serverusername"];
    $params['serverpassword'] = $server["serverpassword"];
    $params['serverid'] = $server["serverid"];

    // Id тарифа в ISPsystem
    $result = DB::table('ispsystem_noc_addon')->select('priceid', 'priceaddon')->where('addonid', $vars['addonid'])->first();
    if (!$result) {
        logModuleCall("hook_ispsystem_partners", "price_find", "Can't find addon price");
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
        logModuleCall("hook_ispsystem_partners", "hosting_find", "Can't find tblhosting domain and ip");
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
    logModuleCall("hook_ispsystem_partners", $message['action'], $message['error']);
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

// Нужно
// продлить привязанные лицензии
// удалить из базы не привязанные лицензии
add_hook('PreCronJob',1,function(){
    $result = DB::table('ispsystem_noc')->select(DB::raw('id, licenseid, serviceid, serverid, (duedate - interval 1 day) duedate, (duedate + interval 1 month) terminatedate'))->get();
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
        logModuleCall("hook_ispsystem_partners", "find_server", "Can't find admin with API access");
        return false;
    }

    $mypath = dirname(__FILE__);
	require_once substr($mypath,0,strpos($mypath,'addons/ispsystem_partners'))."servers/billmanager_noc/billmanager_noc.php";

    foreach ($result as $data) {
        if (!is_null($data->serviceid)){
            // Если лицензия привязана
            if ($today >= $data->duedate) {
                // Продлеваем лицензию за день до окончания срока

                // Найдем адрес, имя\пароль
                $server_data = DB::table('tblservers')->select('id', 'hostname', 'username', 'password')->where('id', $data->serverid)->first();
                if (!$server_data) {
                    logModuleCall("hook_ispsystem_partners", "find_server", "Can't find ISPsystem server");
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
                    logModuleCall("hook_ispsystem_partners", "prolong", "Can't prolong license ".$data->licenseid,$answer['answer']);
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
                logModuleCall("hook_ispsystem_partners", "delete", "Deleting license from database: ".$data->licenseid);
                $count_deleted++;
            }
        }
    }
    date_default_timezone_set($timezone); // Вернем обратно как было
    logModuleCall("hook_ispsystem_partners", "cron", "Prolonged licenses: ".$count_prolonged.". Deleted licenses: ".$count_deleted);
    return true;
});
