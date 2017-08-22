<?php
/*
 *  Module Version: 7.0.2
 */

use WHMCS\Database\Capsule as DB;

function vemanager_MetaData(){
    return array(
        'DisplayName' => 'VEmanager',
        'RequiresServer' => true,
        'AdminSingleSignOnLabel' => 'Login to VEmanager',
    );
}

function vemanager_ConfigOptions() {
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
        "fstype" => [
            "FriendlyName" => "File system",
            "Type" => "dropdown",
            "Options" => "ploop,simfs",
            "Default" => "ploop",
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
            "FriendlyName" => "Processors count",
            "Type" => "text",
            "Size" => "8",
            "Description" => "Unit",
        ],
        "cpufreq" => [
            "FriendlyName" => "Processor frequency",
            "Type" => "text",
            "Size" => "8",
            "Description" => "MHz",
        ],
        "numproc" => [
            "FriendlyName" => "Processes count",
            "Type" => "text",
            "Size" => "8",
            "Description" => "Unit",
        ],
        "numfile" => [
            "FriendlyName" => "Files count",
            "Type" => "text",
            "Size" => "8",
            "Description" => "Unit",
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

function vemanager_AdminServicesTabFields($params) {
    $value = ve_get_external_id($params);
	return array("VEmanager ID" => "<input type='text' name='vemanager_id' size='16' value='".$value."' />");
}

function ve_get_external_id($params) {
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

function ve_save_external_id($params, $external_id) {
    $vmid = ve_get_external_id($params);

	if ($vmid) {
        DB::table('mod_ispsystem')->where('serviceid', $params["serviceid"])->update(['external_id' => $external_id]);
    } else {
        DB::table('mod_ispsystem')->insert(['external_id' => $external_id, 'serviceid' => $params["serviceid"]]);
    }
}

function vemanager_AdminServicesTabFieldsSave($params) {
	ve_save_external_id($params, $_POST["vemanager_id"]);
}

function ve_api_request($ip, $username, $password, $func, $param) {
	global $op;

	$default_xml_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc/>\n";
	$default_xml_error_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc><error type=\"curl\"/></doc>\n";

	$url = "https://".$ip."/vemgr";
	$postfields = array("out" => "xml", "func" => (string)$func, "authinfo" => (string)$username.":".(string)$password, );
	$options = array ('CURLOPT_TIMEOUT' => '60');
	foreach ($param as $key => &$value) {
		$value = (string)$value;
	}

	$response = curlCall($url, array_merge($postfields, $param), $options);

	logModuleCall("vemanager:".$func, $op, array_merge($postfields, $param), $response, $response, array ($password));

	simplexml_load_string($default_xml_string);

	try {
		$out = new SimpleXMLElement($response);
	} catch (Exception $e) {
		$out = simplexml_load_string($default_xml_error_string);
		$out->error->addChild("msg", $e->getMessage());
	}

	return $out;
}

function ve_find_error($xml) {
        $error = "";
        if ($xml->error) {
                $error = $xml->error["type"].":".$xml->error->msg;
        }

        return $error;
}

function ve_encript_password($pass, $func='EncryptPassword') {
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

function ve_decrypt_password($pass) {
    return ve_encript_password($pass, 'DecryptPassword');
}

function vemanager_CreateAccount($params) {
	global $op;
	$op = "create";

	$server_ip = $params["serverip"];
	if ($server_ip == "")
		return "No server!";
	$server_username = $params["serverusername"];
	$server_password = $params["serverpassword"];
	$recipe = $params["configoption12"] === "" ? "null" : $params["configoption12"];
	if (!empty($params["configoption14"])) {
		$params["domain"] = vemanager_GenerateDomain($params);
	}elseif (empty($params["domain"])) {
		$params["configoption14"] = "@ID@.domain";
		$params["domain"] = vemanager_GenerateDomain($params);
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
            $password = ve_decrypt_password($user_data->password);
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

	$user_list = ve_api_request($server_ip, $server_username, $server_password, "user", array());
	$find_user = $user_list->xpath("/doc/elem[level='16' and name='".$service_username."']");
	$user_id = $find_user[0]->id;

	if ($user_id == "") {
		$user_create_param = array (
						"sok" => "ok",
						"level" => "16",
						"name" => $service_username,
						"passwd" => $password,
						);

		$user_create = ve_api_request($server_ip, $server_username, $server_password, "user.edit", $user_create_param);
		$user_id = $user_create->id;

		if ($user_id == "")
			return "Can not create user!";
	}

	$preset_list = ve_api_request($server_ip, $server_username, $server_password, "preset", array());
	$find_preset = $preset_list->xpath("/doc/elem[name='".$params["configoption1"]."']");
	$preset_id = $find_preset[0]->id;

	if ($preset_id == "")
		return "Can not find preset!";

	$container_create_param = [
            "mem" => $params["configoption5"],
            "cpu" => $params["configoption6"],
            "cpufreq" => $params["configoption7"],
            "hdd" => $params["configoption4"],
            "numproc" => $params["configoption8"],
            "numfile" => $params["configoption9"],
            "fstype" => $params["configoption3"],
            "ostemplate" => strtolower($params["configoption2"]),
            "preset" => $preset_id,
            "family" => $params["configoption10"],
            "user" => $user_id,
            "hostnode" => "auto",
            "iptype" => "public",
            "sok" => "ok",
            "password" => $params["password"],
            "confirm" => $params["password"],
            "domain" => $params["domain"],
            "name" => "cont".$params["serviceid"],
            "sshpubkey" => $params["configoption11"],
            "recipe" => $recipe,
	];

	if (array_key_exists("os", $params["configoptions"]))
            $container_create_param["ostemplate"] = strtolower($params["configoptions"]["os"]);
	if (array_key_exists("OS", $params["configoptions"]))
            $container_create_param["ostemplate"] = strtolower($params["configoptions"]["OS"]);
	if (array_key_exists("ostemplate", $params["configoptions"]))
            $container_create_param["ostemplate"] = strtolower($params["configoptions"]["ostemplate"]);
        if (array_key_exists("recipe", $params["configoptions"]))
            $container_create_param["recipe"] = $params["configoptions"]["recipe"];

	$ip_count = $params["configoption10"] == "ipv4" ? -1 : 0;
	$ipv6_count = $params["configoption10"] == "ipv6" ? -1 : 0;

	if (array_key_exists("IP", $params["configoptions"])) {
		$ip_count += $params["configoptions"]["IP"];
        }

	if (array_key_exists("IPv6", $params["configoptions"])) {
                $ipv6_count += $params["configoptions"]["IPv6"];
        }

	$container_create = ve_api_request($server_ip, $server_username, $server_password, "vm.edit", $container_create_param);

	$error = ve_find_error($container_create);
	if ($error != "") {
		return $error;
	}

	$container_id = $container_create->id;

	if ($container_id == "") {
		return "Can not create container!";
	}

	ve_save_external_id($params, $container_id);

	$installed = false;
	$container_ip = "0.0.0.0";
        if ($params["configoption13"] == "on")
            $xpath_expr = "/doc/elem[id='".$container_id."']";
        else
            $xpath_expr = "/doc/elem[id='".$container_id."' and not(installos) and not(installing)]";

	while (1) {
		$container_list = ve_api_request($server_ip, $server_username, $server_password, "vm", array());
		$find_container = $container_list->xpath($xpath_expr);
		if (count($find_container) == 0) {
			sleep(10);
		} else {
			$container_ip = $find_container[0]->ip;
			break;
		}
	}

    DB::table('tblhosting')->where('id', $params["serviceid"])->update(['dedicatedip' => $container_ip]);

	$ip_list = "";
	while ($ip_count > 0) {
        $new_ip_param = array(
            "plid" => $container_id,
            "domain" => $params["domain"],
            "sok" => "ok",
            "family" => "ipv4",
        );

		$ip_add = ve_api_request($server_ip, $server_username, $server_password, "iplist.edit", $new_ip_param);
		$ip_list .= $ip_add->ip."\n";
		$ip_count--;
	}

	while ($ipv6_count > 0) {
        $new_ip_param = array(
            "plid" => $container_id,
            "domain" => $params["domain"],
            "sok" => "ok",
            "family" => "ipv6",
        );

		$ip_add = ve_api_request($server_ip, $server_username, $server_password, "iplist.edit", $new_ip_param);
		$ip_list .= $ip_add->ip."\n";
		$ipv6_count--;
        }

    DB::table('tblhosting')->where('id', $params["serviceid"])->update(['assignedips' => $ip_list]);

	return "success";
}

function ve_process_operation($func, $params) {
        if (isset($_POST["abort"])) return "Operation aborted by user";
	$id = ve_get_external_id($params);
        if ($id == "") {
                return "Unknown container!";
        }

        $server_ip = $params["serverip"];
        $server_username = $params["serverusername"];
        $server_password = $params["serverpassword"];

        $result = ve_api_request($server_ip, $server_username, $server_password, $func, array("elid" => $id));
        $error = ve_find_error($result);

        if ($error != "") {
                return "Error";
        }

        return "success";
}

function vemanager_TerminateAccount($params) {
	global $op;
	$op = "terminate";
	return ve_process_operation("vm.delete", $params);
}

function vemanager_SuspendAccount($params) {
	global $op;
	$op = "suspend";
	return ve_process_operation("vm.stop", $params);
}

function vemanager_UnsuspendAccount($params) {
	global $op;
	$op = "unsuspend";
	return ve_process_operation("vm.start", $params);
}

function vemanager_ChangePassword($params) {
	global $op;
	$op = "change password";
	$server_ip = $params["serverip"];
        $server_username = $params["serverusername"];
        $server_password = $params["serverpassword"];

	$id = ve_get_external_id($params);

	if ($id == "")
		return "Unknown container!";

	$change_password = ve_api_request($server_ip, $server_username, $server_password, "vm.edit", array(
        "elid" => $id,
        "password" => $params["password"],
        "confirm" => $params["password"],
        "sok" => "ok"
    ));
	$error = ve_find_error($change_password);
        if ($error != "") {
                return $error;
        }

        return "success";
}

function vemanager_ChangePackage($params) {
	global $op;
	$op = "change package";
	$server_ip = $params["serverip"];
        $server_username = $params["serverusername"];
        $server_password = $params["serverpassword"];

        $id = ve_get_external_id($params);

        if ($id == "")
                return "Unknown container!";

	$preset_list = ve_api_request($server_ip, $server_username, $server_password, "preset", array());
        $find_preset = $preset_list->xpath("/doc/elem[name='".$params["configoption1"]."']");
        $preset_id = $find_preset[0]->id;

        if ($preset_id == "")
                return "Can not find preset!";

	$preset = ve_api_request($server_ip, $server_username, $server_password, "preset.edit", array("elid" => $preset_id));
	$container_change_param = array (
        "elid" => $id,
        "mem" => $preset->mem,
        "cpu" => $preset->cpu,
        "cpufreq" => $preset->cpufreq,
        "hdd" => $preset->hdd,
        "numproc" => $preset->numproc,
        "numfile" => $preset->numfile,
        "sok" => "ok",
        );

	if (array_key_exists("configoption5", $params) && $params["configoption5"] != "")
		$container_change_param["mem"] = $params["configoption5"];

    if (array_key_exists("configoption6", $params) && $params["configoption6"] != "")
            $container_change_param["cpu"] = $params["configoption6"];

    if (array_key_exists("configoption7", $params) && $params["configoption7"] != "")
            $container_change_param["cpufreq"] = $params["configoption7"];

    if (array_key_exists("configoption4", $params) && $params["configoption4"] != "")
            $container_change_param["hdd"] = $params["configoption4"];

    if (array_key_exists("configoption8", $params) && $params["configoption8"] != "")
            $container_change_param["numproc"] = $params["configoption8"];

    if (array_key_exists("configoption9", $params) && $params["configoption9"] != "")
            $container_change_param["numfile"] = $params["configoption9"];

    if (array_key_exists("configoption9", $params) && $params["configoption9"] != "")
            $container_change_param["numfile"] = $params["configoption9"];

	$change_package = ve_api_request($server_ip, $server_username, $server_password, "vm.edit", $container_change_param);
        $error = ve_find_error($change_package);
        if ($error != "") {
                return $error;
        }

        return "success";
}

function vemanager_ClientAreaCustomButtonArray() {
    return [
        "Reboot Server" => "reboot",
        "Stop Server" => "poweroff",
        "Start Server" => "poweron",
        "Reinstall Server" => "reinstall",
    ];
}

function vemanager_AdminCustomButtonArray() {
	return [
		"Reboot Server" => "m_reboot",
		"Stop Server" => "m_poweroff",
		"Start Server" => "poweron",
		"Reinstall Server" => "reinstall",
	];
}

function vemanager_m_reboot($params) {
	global $op;
	$op = "reboot";
	return ve_process_operation("vm.restart", $params);
}

function vemanager_reboot($params) {
    if (isset($_POST["a"]))
        return vemanager_m_reboot($params);

    return [
        'templatefile' => 'alert',
        'vars' => [
            'action' => 'reboot',
            'description' => 'Reboot Server'
        ]
    ];
}

function vemanager_m_poweroff($params) {
	global $op;
	$op = "stop";
	return ve_process_operation("vm.stop", $params);
}

function vemanager_poweroff($params) {
    if (isset($_POST["a"]))
        return vemanager_m_poweroff($params);

    return [
        'templatefile' => 'alert',
        'vars' => [
            'action' => 'poweroff',
            'description' => 'Stop Server'
        ]
    ];
}

function vemanager_poweron($params) {
	global $op;
	$op = "start";
	return ve_process_operation("vm.start", $params);
}

function vemanager_reinstall($params) {
    global $op;
    $op = "reinstall";

    if ($_POST["reinstallation"] == "on") {
        $server_ip = $params["serverip"];
        $server_username = $params["serverusername"];
        $server_password = $params["serverpassword"];

        $vm_param = [
            "elid" => ve_get_external_id($params),
            "ostemplate" => $params["configoption2"],
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
            $vm_param["ostemplate"] = ($params["configoptions"]["os"]);
        if (array_key_exists("OS", $params["configoptions"]))
            $vm_param["ostemplate"] = ($params["configoptions"]["OS"]);
        if (array_key_exists("ostemplate", $params["configoptions"]))
            $vm_param["ostemplate"] = ($params["configoptions"]["ostemplate"]);
        if (array_key_exists("recipe", $params["configoptions"]))
            $vm_param["recipe"] = $params["configoptions"]["recipe"];

        $result = ve_api_request($server_ip, $server_username, $server_password, "vm.reinstall", $vm_param);
        $error = ve_find_error($result);

        return !empty($error) ? $error : "success";;
    } else {
            return ['templatefile' => 'os'];
    }
}

function vemanager_ClientArea($params) {
	global $op;
	$op = "client area";
	$code = "";
	if ($_POST["process_vemanager"] == "true") {
		$server_ip = $params["serverip"];
	        $server_username = $params["serverusername"];
        	$server_password = $params["serverpassword"];

		$key = md5(time()).md5($params["username"]);
		$newkey = ve_api_request($server_ip, $server_username, $server_password, "session.newkey", array("username" => $params["username"],
	                                                                                                       	"key" => $key));
		$error = ve_find_error($newkey);
        	if ($error != "") {
                	return "Error";
        	}

		$code = "<form action='https://".$params["serverip"]."/vemgr' method='post' name='velogin'>
                        <input type='hidden' name='func' value='auth' />
			<input type='hidden' name='username' value='".$params["username"]."' />
			<input type='hidden' name='checkcookie' value='no' />
			<input type='hidden' name='key' value='".$key."' />
                        <input type='submit' value='Login to Control Panel' class='button'/>
                        </form>
			<script language='JavaScript'>document.velogin.submit();</script>";
	} else {
		$code = "<form action='clientarea.php' method='post' target='_blank'>
			<input type='hidden' name='action' value='productdetails' />
			<input type='hidden' name='id' value='".$params["serviceid"]."' />
			<input type='hidden' name='process_vemanager' value='true' />
			<input type='submit' value='Login to Control Panel' class='button'/>
			</form>";
	}

	return $code;
}

/* Сбор статистики с VEmgr */
function vemanager_UsageUpdate($params) {
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
		logActivity("VEmanager get stats error: ".mysql_error());

	foreach ($result as $data) {
		$external_id = $data->external_id;

		if ($external_id == "")
			continue;

		$reportvm = ve_api_request($server_ip, $server_username, $server_password, "reportvm",
					array("interval" => "day",
                                              "period" => "currentmonth",
					      "vm" => $external_id));

		$infovm = ve_api_request($server_ip, $server_username, $server_password, "vm.sysinfo",
                                        array("elid" => $external_id));

		$diskusage = 0;
		$disklimit = 0;
		$bwusage = 0;

		$x_tx = $reportvm->xpath("//elem/tx");

		while(list( , $tx) = each($x_tx)) {
			$bwusage = (floatval($bwusage) + 1024.0 * floatval($tx));
		}

		$x_rx = $reportvm->xpath("//elem/rx");

		while(list( , $rx) = each($x_rx)) {
                        $bwusage = (floatval($bwusage) + 1024.0 * floatval($rx));
                }

		$disk_summary = $infovm->xpath("//elem[name='hdd']");
		$disk_summary = $disk_summary[0]->value;
		preg_match_all('!\d+!', $disk_summary, $matches);

		$diskusage = $matches[0][1];
		$disklimit = $matches[0][0];

		if ($disklimit > 0) {
            DB::table('tblhosting')
                ->where('id', $data->id)
                ->update([
                    'diskusage' => $diskusage,
                    'disklimit' => $disklimit,
                    'bwusage' => $bwusage,
                    'lastupdate' => 'now()'
                ]);
		} else {
            DB::table('tblhosting')
                ->where('id', $data->id)
                ->update([
                    'bwusage' => $values['bwusage'],
                    'lastupdate' => 'now()'
                ]);
		}
	}
}

function vemanager_AdminSingleSignOn($params){
    global $op;
    $op = "auth";

    $server_ip = $params["serverip"];
    $server_username = $params["serverusername"];
    $server_password = $params["serverpassword"];

    try {
        $key = md5(time()).md5($params["username"]);
        $newkey = ve_api_request($server_ip, $server_username, $server_password, "session.newkey", ["username" => $server_username, "key" => $key]);
        $error = ve_find_error($newkey);

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
function vemanager_GenerateDomain(&$params)
{
	$domain = $params["configoption14"];
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
