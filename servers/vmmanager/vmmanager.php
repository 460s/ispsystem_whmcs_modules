<?php
use WHMCS\Database\Capsule as DB;

function vmmanager_MetaData(){
    return array(
		'DisplayName' => 'VMmanager',
		'RequiresServer' => true,
    );
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

/* 
 * Получить список пользователей
 * Создать пользователя
 * Создать контейнер
 */
function vmmanager_CreateAccount($params) {
	global $op;
	$op = "create";

	$server_ip = $params["serverip"];
	if ($server_ip == "")
                return "No server!";
	$server_username = $params["serverusername"];
	$server_password = $params["serverpassword"];
        //Если услуга не новая, задаем значение по умолчанию
        $params["configoption9"] === "" ? $recipe = "null" : $recipe = $params["configoption9"];

	$service_username = $params["username"];
	$user_list = vm_api_request($server_ip, $server_username, $server_password, "user", array());
	$find_user = $user_list->xpath("/doc/elem[level='16' and name='".$service_username."']");
	$user_id = $find_user[0]->id;

	if ($user_id == "") {
		$user_create_param = [
                    "sok" => "ok",
                    "level" => "16",
                    "name" => $service_username,
                    "passwd" => $params["password"],
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
            "vmi" => ($params["configoption2"]),
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
            
	while (1) {
		$vm_list = vm_api_request($server_ip, $server_username, $server_password, "vm", array());
		$find_vm = $vm_list->xpath($xpath_expr);
		if (count($find_vm) == 0) {
			sleep(30);
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
	$id = vm_get_external_id($params);
        if ($id == "") {
                return "Unknown vm!";
        }

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
	$op = "unsusoend";
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
    return ["Reboot Server" => "reboot"];
}

function vmmanager_AdminCustomButtonArray() {
	return vmmanager_ClientAreaCustomButtonArray();
}

function vmmanager_reboot($params) {
	global $op;
	$op = "reboot";
	return vm_process_operation("vm.restart", $params);
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

function vmmanager_AdminLink($params) {
        global $op;
        $op = "client area";
        $code = "";
        if ($_POST["process_vmmanager"] == "true" && $_POST["process_ip"] == $params["serverip"]) {
                $server_ip = $params["serverip"];
                $server_username = $params["serverusername"];
                $server_password = $params["serverpassword"];

                $key = md5(time()).md5($params["username"]);
                $newkey = vm_api_request($server_ip, $server_username, $server_password, "session.newkey", array("username" => $server_username,
                                                                                                                "key" => $key));
                $error = vm_find_error($newkey);
                if ($error != "") {
                        return $error;
		}

                $code = "<form action='https://".$params["serverip"]."/vmmgr' method='post' name='vmlogin'>
                        <input type='hidden' name='func' value='auth' />
                        <input type='hidden' name='username' value='".$server_username."' />
                        <input type='hidden' name='checkcookie' value='no' />
                        <input type='hidden' name='key' value='".$key."' />
                        <input type='submit' value='VMmanager' class='button'/>
                        </form>
                        <script language='JavaScript'>document.vmlogin.submit();</script>";
        } else {
                $code = "<form action='configservers.php' method='post' target='_blank'>
                        <input type='hidden' name='process_vmmanager' value='true' />
			<input type='hidden' name='process_ip' value='".$params["serverip"]."' />
                        <input type='submit' value='VMmanager' class='button'/>
                        </form>";
        }

        return $code;
}

?>
