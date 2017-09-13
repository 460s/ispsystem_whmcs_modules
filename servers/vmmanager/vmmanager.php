<?php
/*
 *  Module Version: 7.0.2
 */

use WHMCS\Database\Capsule as DB;

function vmmanager_MetaData(){
    return [
        'DisplayName' => 'VMmanager',
        'RequiresServer' => true,
        'AdminSingleSignOnLabel' => 'Login to VMmanager',
        ];
}

function vmmanager_ConfigOptions() {
    return [
        "package" => [
            "FriendlyName" => "Package Name",
            "Type" => "text",
            "Size" => "25",
        ],
        "os" => [
            "FriendlyName" => "Operation system",
            "Type" => "text",
            "Size" => "64",
        ],
        "hdd" => [
            "FriendlyName" => "Disk quota",
            "Type" => "text",
            "Size" => "8",
            "Description" => "MiB",
        ],
        "mem" => [
            "FriendlyName" => "Memory quota",
            "Type" => "text",
            "Size" => "8",
            "Description" => "MiB",
        ],
        "cpu" => [
            "FriendlyName" => "CPU",
            "Type" => "text",
            "Size" => "8",
            "Description" => "Pcs",
        ],
        "cpufreq" => [
            "FriendlyName" => "CPU weight",
            "Type" => "text",
            "Size" => "8",
            "Description" => "Cgroups weight for CPU",
        ],
        "family" => [
            "FriendlyName" => "Main IP address type",
            "Type" => "dropdown",
            "Options" => "ipv4,ipv6",
            "Default" => "ipv4",
        ],
        "sshkey" => [
            "FriendlyName" => "SSH public key",
            "Type" => "textarea",
            "Rows" => "10",
            "Cols" => "30",
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
    ];
}

function vmmanager_AdminServicesTabFields($params) {
	$value = vm_get_external_id($params);
	return ["VMmanager ID" => "<input type='text' name='vmmanager_id' size='16' value='".$value."' />"];
}

function vm_get_external_id($params) {
	$result = DB::table('mod_ispsystem')
		->select('external_id')
		->where([
                    ['serviceid', $params["serviceid"]],
                    ['external_id', '<>', ''],
                ])
		->first();
	if ($result) {
		return $result->external_id;
	} else {
		return "";
	}
}

function vm_save_external_id($params, $external_id) {
	$vmid = vm_get_external_id($params);

	if ($vmid) {
		DB::table('mod_ispsystem')->where('serviceid', $params["serviceid"])->update(['external_id' => $external_id]);
	} else {
		DB::table('mod_ispsystem')->insert(['external_id' => $external_id, 'serviceid' => $params["serviceid"]]);
	}
}

function vmmanager_AdminServicesTabFieldsSave($params) {
	vm_save_external_id($params, $_POST["vmmanager_id"]);
}

function vm_api_request($ip, $username, $password, $func, $param) {
	global $op;

	$default_xml_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc/>\n";
	$default_xml_error_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc><error type=\"curl\"/></doc>\n";

	$url = "https://".$ip."/vmmgr";
	$postfields = array("out" => "xml", "func" => (string)$func, "authinfo" => (string)$username.":".(string)$password, );
	$options = array ('CURLOPT_TIMEOUT' => '60');
	foreach ($param as $key => &$value) {
		$value = (string)$value;
	}

	$response = curlCall($url, array_merge($postfields, $param), $options);
	logModuleCall("VMmanager:".$func, $op, array_merge($postfields, $param), $response, $response, array ($password));

	simplexml_load_string($default_xml_string);

	try {
		$out = new SimpleXMLElement($response);
	} catch (Exception $e) {
		$out = simplexml_load_string($default_xml_error_string);
		$out->error->addChild("msg", $e->getMessage());
	}

	return $out;
}

function vm_request($ip, $func, $param) {
	global $op;

	$default_xml_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc/>\n";
	$default_xml_error_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc><error type=\"curl\"/></doc>\n";

	$url = "https://".$ip."/vmmgr";
	$postfields = array("out" => "xml", "func" => (string)$func, );
	$options = array ('CURLOPT_TIMEOUT' => '60');
	foreach ($param as $key => &$value) {
		$value = (string)$value;
	}

	$response = curlCall($url, array_merge($postfields, $param), $options);
	logModuleCall("VMmanager:".$func, $op, array_merge($postfields, $param), $response, $response);

	$out = simplexml_load_string($default_xml_string);

	try {
		$out = new SimpleXMLElement($response);
	} catch (Exception $e) {
		$out = simplexml_load_string($default_xml_error_string);
		$out->error->addChild("msg", $e->getMessage());
	}

	return $out;
}

function vm_find_error($xml) {
        $error = "";
        if ($xml->error) {
                $error = $xml->error["type"].":".$xml->error->msg;
        }

        return $error;
}

function vm_encript_password($pass, $func='EncryptPassword') {
    $admin_data = DB::table('tbladmins')
        ->leftJoin('tbladminperms', 'tbladmins.roleid', '=', 'tbladminperms.roleid')
        ->where([
            ['tbladmins.disabled', '=', 0],
            ['tbladminperms.permid', '=', 81],
        ])
        ->select('tbladmins.username')
        ->first();
    $pass_data = localAPI($func, array("password2" => $pass), $admin_data->username);
    logModuleCall("vmmanager:vm", $func, $pass, $pass_data['result']);

    return $pass_data['result'] === 'success' ? $pass_data['password'] : "";
}

function vm_decrypt_password($pass) {
    return vm_encript_password($pass, 'DecryptPassword');
}

function vmmanager_CreateAccount($params) {
    global $op;
    $op = "create";

    $server_ip = $params["serverip"];
    if ($server_ip == "")
            return "No server!";
    $server_username = $params["serverusername"];
    $server_password = $params["serverpassword"];
    $recipe = $params["configoption9"] === "" ? "null" : $params["configoption9"];
	if (!empty($params["configoption11"])) {
		$params["domain"] = vmmanager_GenerateDomain($params);
	}elseif (empty($params["domain"])) {
		$params["configoption11"] = "@ID@.domain";
		$params["domain"] = vmmanager_GenerateDomain($params);
	}

    $user_data = DB::table('tblhosting')
        ->join('tblorders', 'tblhosting.orderid', '=', 'tblorders.id')
        ->select('username','password')
        ->where([
            ['tblhosting.userid',  $params["clientsdetails"]["userid"]],
            ['tblhosting.server', $params["serverid"]],
            ['tblorders.status', "Active"],
        ])
        ->first();

    if ($user_data){
        $service_username = $user_data->username;
        $password = vm_decrypt_password($user_data->password);
        if(empty($password)) return "cant decrypt password";
        DB::table('tblhosting')
            ->where('id', $params["serviceid"])
            ->update([
                'username' => $service_username,
                'password' => $user_data->password,
            ]);
    }else{
        $service_username = $params["username"];
        $password = $params["password"];
    }

    $user_list = vm_api_request($server_ip, $server_username, $server_password, "user", array());
    $find_user = $user_list->xpath("/doc/elem[level='16' and name='".$service_username."']");
    $user_id = $find_user[0]->id;

    if ($user_id == "") {
            $user_create_param = [
                "sok" => "ok",
                "level" => "16",
                "name" => $service_username,
                "passwd" => $password,
            ];

            $user_create = vm_api_request($server_ip, $server_username, $server_password, "user.edit", $user_create_param);
            $user_id = $user_create->id;

            if ($user_id == "")
                    return "Can not create user!";
    }

    $preset_list = vm_api_request($server_ip, $server_username, $server_password, "preset", array());
    $find_preset = $preset_list->xpath("/doc/elem[name='".$params["configoption1"]."']");
    $preset_id = $find_preset[0]->id;

    if ($preset_id == "")
            return "Can not find preset!";

    $vm_create_param = [
        "mem" => $params["configoption4"],
        "vcpu" => $params["configoption5"],
        "cputune" => $params["configoption6"],
        "vsize" => $params["configoption3"],
        "vmi" => $params["configoption2"],
        "preset" => $preset_id,
        "family" => $params["configoption7"],
        "user" => $user_id,
        "hostnode" => "auto",
        "iptype" => "public",
        "sok" => "ok",
        "password" => $params["password"],
        "confirm" => $params["password"],
        "domain" => $params["domain"],
        "name" => "cont".$params["serviceid"],
        "sshpubkey" => $params["configoption8"],
        "recipe" => $recipe,
    ];

    if (array_key_exists("os", $params["configoptions"]))
        $vm_create_param["vmi"] = ($params["configoptions"]["os"]);
    if (array_key_exists("OS", $params["configoptions"]))
        $vm_create_param["vmi"] = ($params["configoptions"]["OS"]);
    if (array_key_exists("ostemplate", $params["configoptions"]))
        $vm_create_param["vmi"] = ($params["configoptions"]["ostemplate"]);
    if (array_key_exists("vmi", $params["configoptions"]))
        $vm_create_param["vmi"] = ($params["configoptions"]["vmi"]);
    if (array_key_exists("recipe", $params["configoptions"]))
        $vm_create_param["recipe"] = $params["configoptions"]["recipe"];

    $ip_count = $params["configoption7"] == "ipv4" ? -1 : 0;
    $ipv6_count = $params["configoption7"] == "ipv6" ? -1 : 0;
    if (array_key_exists("IP", $params["configoptions"]))
            $ip_count += $params["configoptions"]["IP"];
    if (array_key_exists("IPv6", $params["configoptions"]))
            $ipv6_count += $params["configoptions"]["IPv6"];

    $vm_create = vm_api_request($server_ip, $server_username, $server_password, "vm.edit", $vm_create_param);

    $error = vm_find_error($vm_create);
    if ($error != "") {
            return $error;
    }

    $vm_id = $vm_create->id;

    if ($vm_id == "") {
            return "Can not create vm!";
    }

    vm_save_external_id($params, $vm_id);

    $vm_ip = "0.0.0.0";
    if ($params["configoption10"] == "on")
        $xpath_expr = "/doc/elem[id='".$vm_id."']";
    else
        $xpath_expr = "/doc/elem[id='".$vm_id."' and not(installos) and not(installing)]";

	$num = 40;
    while ($num) {
		$vm_list = vm_api_request($server_ip, $server_username, $server_password, "vm", array());
		$find_vm = $vm_list->xpath($xpath_expr);
		logModuleCall("vmmanager", "xpath", $xpath_expr, $find_vm, $find_vm);
		if (count($find_vm) == 0) {
			sleep(30);
			$num--;
		} else {
			$vm_ip = $find_vm[0]->ip;
			break;
		}
    }

    DB::table('tblhosting')->where('id', $params["serviceid"])->update(['dedicatedip' => $vm_ip]);

    $ip_list = "";
    while ($ip_count > 0) {
            $new_ip_param = array(
                                    "plid" => $vm_id,
                                    "domain" => $params["domain"],
                                    "sok" => "ok",
                                    "family" => "ipv4",
                                    );

            $ip_add = vm_api_request($server_ip, $server_username, $server_password, "iplist.edit", $new_ip_param);
            $ip_list .= $ip_add->ip."\n";
            $ip_count--;
    }

    while ($ipv6_count > 0) {
            $new_ip_param = array(
                                    "plid" => $vm_id,
                                    "domain" => $params["domain"],
                                    "sok" => "ok",
                                    "family" => "ipv6",
                                    );

            $ip_add = vm_api_request($server_ip, $server_username, $server_password, "iplist.edit", $new_ip_param);
            $ip_list .= $ip_add->ip."\n";
            $ipv6_count--;
    }

    DB::table('tblhosting')->where('id', $params["serviceid"])->update(['assignedips' => $ip_list]);

    return "success";
}

function vm_process_operation($func, $params) {
        if (isset($_POST["abort"])) return "Operation aborted by user";
	$id = vm_get_external_id($params);
        if (empty($id)) return "Unknown vm!";

        $server_ip = $params["serverip"];
        $server_username = $params["serverusername"];
        $server_password = $params["serverpassword"];

        $result = vm_api_request($server_ip, $server_username, $server_password, $func, array("elid" => $id));
        $error = vm_find_error($result);

        if ($error != "") {
                return "Error";
        }

        return "success";
}

function vmmanager_TerminateAccount($params) {
	global $op;
	$op = "terminate";
	return vm_process_operation("vm.delete", $params);
}

function vmmanager_SuspendAccount($params) {
	global $op;
	$op = "suspend";
	return vm_process_operation("vm.stop", $params);
}

function vmmanager_UnsuspendAccount($params) {
	global $op;
	$op = "unsuspend";
	return vm_process_operation("vm.start", $params);
}

function vmmanager_ChangePackage($params) {
	global $op;
	$op = "change package";
	$server_ip = $params["serverip"];
        $server_username = $params["serverusername"];
        $server_password = $params["serverpassword"];

        $id = vm_get_external_id($params);

        if ($id == "")
                return "Unknown vm!";

	$preset_list = vm_api_request($server_ip, $server_username, $server_password, "preset", array());
        $find_preset = $preset_list->xpath("/doc/elem[name='".$params["configoption1"]."']");
        $preset_id = $find_preset[0]->id;

        if ($preset_id == "")
                return "Can not find preset!";

	$preset = vm_api_request($server_ip, $server_username, $server_password, "preset.edit", array("elid" => $preset_id));
	$vm_change_param = array (
					"elid" => $id,
                                        "mem" => $preset->mem,
                                        "vcpu" => $preset->vcpu,
                                        "cputune" => $preset->cputune,
                                        "sok" => "ok",
                                        );

	if (array_key_exists("configoption4", $params) && $params["configoption4"] != "")
		$vm_change_param["mem"] = $params["configoption4"];

        if (array_key_exists("configoption5", $params) && $params["configoption5"] != "")
                $vm_change_param["vcpu"] = $params["configoption5"];

        if (array_key_exists("configoption6", $params) && $params["configoption6"] != "")
                $vm_change_param["cputune"] = $params["configoption6"];

	$change_package = vm_api_request($server_ip, $server_username, $server_password, "vm.edit", $vm_change_param);
        $error = vm_find_error($change_package);
        if ($error != "") {
                return $error;
        }

        return "success";
}

function vmmanager_ClientAreaCustomButtonArray() {
    return [
        "Reboot Server" => "reboot",
        "Stop Server" => "poweroff",
        "Start Server" => "poweron",
        "Reinstall Server" => "reinstall",
    ];
}

function vmmanager_AdminCustomButtonArray() {
	return [
		"Reboot Server" => "m_reboot",
		"Stop Server" => "m_poweroff",
		"Start Server" => "poweron",
		"Reinstall Server" => "reinstall",
	];
}

function vmmanager_m_reboot($params) {
	global $op;
	$op = "reboot";
	return vm_process_operation("vm.restart", $params);
}

function vmmanager_reboot($params) {
    if (isset($_POST["a"]))
        return vmmanager_m_reboot($params);

    return [
        'templatefile' => 'alert',
        'vars' => [
            'action' => 'reboot',
            'description' => 'Reboot Server'
        ]
    ];
}

function vmmanager_m_poweroff($params) {
	global $op;
	$op = "stop";
	return vm_process_operation("vm.stop", $params);
}

function vmmanager_poweroff($params) {
    if (isset($_POST["a"]))
        return vmmanager_m_poweroff($params);

    return [
        'templatefile' => 'alert',
        'vars' => [
            'action' => 'poweroff',
            'description' => 'Stop Server'
        ]
    ];
}

function vmmanager_poweron($params) {
    global $op;
    $op = "start";
    return vm_process_operation("vm.start", $params);
}

function vmmanager_reinstall($params) {
    global $op;
    $op = "reinstall";

    if ($_POST["reinstallation"] == "on") {
        $server_ip = $params["serverip"];
        $server_username = $params["serverusername"];
        $server_password = $params["serverpassword"];

        $vm_param = [
            "elid" => vm_get_external_id($params),
            "vmi" => $params["configoption2"],
            "sok" => "ok",
            "password" => $params["password"],
            "confirm" => $params["password"],
            "recipe" => "null",
        ];

        if(!empty($_POST["passwd"])){
            $vm_param["new_password"] = "on";
            $vm_param["password"] = $_POST["passwd"];
            $vm_param["confirm"] = $_POST["passwd"];
        } else {
            $vm_param["new_password"] = "off";
        }
        if (array_key_exists("os", $params["configoptions"]))
            $vm_param["vmi"] = ($params["configoptions"]["os"]);
        if (array_key_exists("OS", $params["configoptions"]))
            $vm_param["vmi"] = ($params["configoptions"]["OS"]);
        if (array_key_exists("ostemplate", $params["configoptions"]))
            $vm_param["vmi"] = ($params["configoptions"]["ostemplate"]);
        if (array_key_exists("vmi", $params["configoptions"]))
            $vm_param["vmi"] = ($params["configoptions"]["vmi"]);
        if (array_key_exists("recipe", $params["configoptions"]))
            $vm_param["recipe"] = $params["configoptions"]["recipe"];

        $result = vm_api_request($server_ip, $server_username, $server_password, "vm.reinstall", $vm_param);
        $error = vm_find_error($result);

        return !empty($error) ? $error : "success";;
    } else {
            return ['templatefile' => 'os'];
    }
}

/*
 * Форма target _blank передает признак перехода в vmmgr
 * При получении признака генерируется ключ сессии для текущего пользователя
 * С ключем выполняются все перенаправления в vmmgr со связкой key+func+prop
 */
function vmmanager_ClientArea($params) {
	global $op;
	$op = "client area";

	if ($_POST["process_vmmanager"] == "true") {
		if (empty($params["username"])){
			$code = "Authorization failed. User is empty";
		}
		$authinfo = vm_request($params["serverip"], "auth", array("username" => $params["username"], "password" => $params["password"]));
		$auth_id = $authinfo->auth;

		if (isset($_POST["novnc"])){
			$elid = vm_get_external_id($params);
			$args = "&func=vm.novnc&newwindow=yes&elid=".$elid;
		}

		header("Location: https://".$params["serverip"]."/vmmgr?auth=".$auth_id.$args);
		exit;
	} else {
		$code = "<form action='clientarea.php' method='post' target='_blank'>
			<input type='hidden' name='action' value='productdetails' />
			<input type='hidden' name='id' value='".$params["serviceid"]."' />
			<input type='hidden' name='process_vmmanager' value='true' />
			<input type='submit' name='auth' value='Login to Control Panel'/>
                        <input type='submit' name='novnc' value='Open noVNC client'/>
			</form>";
	}
	return $code;
}

function vmmanager_UsageUpdate($params) {
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

	if (!$result)
		logActivity("VMmanager get stats error: ".mysql_error());

	foreach ($result as $data) {
		$external_id = $data->external_id;

		if ($external_id == "")
			continue;

		$reportvm = vm_api_request($server_ip, $server_username, $server_password, "reportvm",
					array("type" => "day",
                                              "period" => "currentmonth",
					      "server" => $external_id));

		$bwusage = 0;

		$x_tx = $reportvm->xpath("//elem/tx");

		while(list( , $tx) = each($x_tx)) {
			$bwusage = (floatval($bwusage) + 1024.0 * floatval($tx));
		}

		$x_rx = $reportvm->xpath("//elem/rx");

		while(list( , $rx) = each($x_rx)) {
                        $bwusage = (floatval($bwusage) + 1024.0 * floatval($rx));
                }

		DB::table('tblhosting')
            ->where('id', $data->id)
            ->update([
                'bwusage' => $bwusage,
                'lastupdate' => 'now()'
            ]);
	}
}

function vmmanager_AdminSingleSignOn($params){
    global $op;
    $op = "auth";

    $server_ip = $params["serverip"];
    $server_username = $params["serverusername"];
    $server_password = $params["serverpassword"];

    try {
        $key = md5(time()).md5($params["username"]);
        $newkey = vm_api_request($server_ip, $server_username, $server_password, "session.newkey", ["username" => $server_username, "key" => $key]);
        $error = vm_find_error($newkey);

        if (!empty($error)) {
             return  ['success' => false, 'errorMsg' => $error];
        }

        return [
            'success' => true,
            'redirectTo' => "https://".$server_ip."/vmmgr?checkcookie=no&func=auth&username=".$server_username."&key=".$key,
        ];
    } catch (Exception $e) {
         return [
            'success' => false,
            'errorMsg' => $e->getMessage(),
        ];
    }
}

/*
 * Генерация доменного имени на основе заданного шаблона
 * @var $params Массив параметров WHMCS
 */
function vmmanager_GenerateDomain(&$params)
{
	$domain = $params["configoption11"];
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
?>
