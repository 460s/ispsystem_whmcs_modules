<?php

$op = "";

function ispmanager_MetaData(){
    return array(
        'DisplayName' => 'ISPmanager',
        'RequiresServer' => true,
    );
}

function ispmanager_ConfigOptions() {
	return array(
		"package" => array(
					"FriendlyName" => "Package Name",
					"Type" => "text",
					"Size" => "25",
				),
		"limit_quota" =>	array(
					"FriendlyName" => "Disk quota",
					"Type" => "text",
					"Size" => "8",
					"Description" => "MiB",
				),
		"limit_traff" =>	array(
					"FriendlyName" => "Traffic quota",
					"Type" => "text",
					"Size" => "8",
					"Description" => "MiB",
				),
		"limit_db" =>	array(
					"FriendlyName" => "Db count",
					"Type" => "text",
					"Size" => "8",
					"Description" => "Unit",
				),
		"limit_db_users" =>	array(
					"FriendlyName" => "Db user count",
					"Type" => "text",
					"Size" => "8",
					"Description" => "Unit",
				),
		"limit_ftp_users" =>	array(
					"FriendlyName" => "FTP user count",
					"Type" => "text",
					"Size" => "8",
					"Description" => "Unit",
				),
		"limit_webdomains" =>	array(
					"FriendlyName" => "Web domains count",
					"Type" => "text",
					"Size" => "8",
					"Description" => "Unit",
				),
		"limit_emaildomains" =>	array(
					"FriendlyName" => "Email domains count",
					"Type" => "text",
					"Size" => "8",
					"Description" => "Unit",
				),
		"limit_emails" =>	array(
					"FriendlyName" => "Email box count",
					"Type" => "text",
					"Size" => "8",
					"Description" => "Unit",
				),
		"limit_cpu" =>	array(
					"FriendlyName" => "CPU time",
					"Type" => "text",
					"Size" => "8",
					"Description" => "Unit",
				),
		"limit_memory" =>	array(
					"FriendlyName" => "Memory limit",
					"Type" => "text",
					"Size" => "8",
					"Description" => "MiB",
				),
		"limit_process" =>	array(
					"FriendlyName" => "Processes count limit",
					"Type" => "text",
					"Size" => "8",
					"Description" => "Unit",
				),
		"limit_email_quota" =>	array(
					"FriendlyName" => "Email box quota",
					"Type" => "text",
					"Size" => "8",
					"Description" => "MiB",
				),
		"family" =>	array(
					"FriendlyName" => "Main IP address type",
					"Type" => "dropdown",
					"Options" => "shared,ipv4,ipv6",
					"Default" => "shared",
				),
		"username_template" =>	array(
					"FriendlyName" => "Username template",
					"Type" => "text",
					"Size" => "32",
					"Description" => "<br/><br/>@ID@ - service id<br/>@DOMAIN@ - domain name",
				),
	);
}

function ispmgr_get_external_id($params) {
	return $params["username"];
}

function ispmgr_api_request($ip, $username, $password, $func, $param) {
	global $op;

	$default_xml_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc/>\n";
	$default_xml_error_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc><error type=\"curl\"/></doc>\n";

	$url = "https://".$ip."/ispmgr";
	$postfields = array("out" => "xml", "func" => (string)$func, "authinfo" => (string)$username.":".(string)$password, );
	$options = array ('CURLOPT_TIMEOUT' => '60');
	foreach ($param as $key => &$value) {
		$value = (string)$value;
	}

	$response = curlCall($url, array_merge($postfields, $param), $options);

	logModuleCall("ISPmanager:".$func, $op, array_merge($postfields, $param), $response, $response, array ($password));

	simplexml_load_string($default_xml_string);

	try {
		$out = new SimpleXMLElement($response);
	} catch (Exception $e) {
		$out = simplexml_load_string($default_xml_error_string);
		$out->error->addChild("msg", $e->getMessage());
	}

	return $out;
}

function ispmgr_find_error($xml) {
	$error = "";
	if ($xml->error) {
			$error = $xml->error["type"].":".$xml->error->msg;
	}

	return $error;
}

function ispmanager_GenerateUsername($params, $template) {
	$template = str_replace("@ID@", $params["serviceid"], $template);
	$template = str_replace("@DOMAIN@", $params["domain"], $template);

	$result = select_query("tblhosting","tblhosting.username",array("tblhosting.username" => $template, "id"=>array("sqltype"=>"NEQ","value"=>$params["serviceid"])));
	$num = 1;
	while (mysql_num_rows($result) > 0) {
		$template = $template + string($num);
		$result = select_query("tblhosting","tblhosting.username",array("tblhosting.username" => $template, "id"=>array("sqltype"=>"NEQ","value"=>$params["serviceid"])));
		$num++;
	}

	update_query("tblhosting", array("username" => $template), array("id" => $params["serviceid"]));

	return $template;
}

function ispmanager_CreateAccount($params) {
	global $op;
	$op = "create";

	$server_ip = $params["serverip"];
	if ($server_ip == "")
		return "No server!";

	$server_username = $params["serverusername"];
	$server_password = $params["serverpassword"];

	$service_username = $params["username"];

	if (array_key_exists("configoption15", $params) && $params["configoption15"] != "") {
		$service_username = ispmanager_GenerateUsername($params, $params["configoption15"]);
	}

	$user_list = ispmgr_api_request($server_ip, $server_username, $server_password, "user", array());
	$find_user = $user_list->xpath("/doc/elem[level='16' and name='".$service_username."']");
	$user_name = $find_user[0]->name;

	if ($user_name != "") {
		return "User with same username exists!";
	}

	$preset_list = ispmgr_api_request($server_ip, $server_username, $server_password, "preset", array());
	$find_preset = $preset_list->xpath("/doc/elem[name='".$params["configoption1"]."']");
	$preset_name = $find_preset[0]->name;

	if ($preset_name == "")
		return "Can not find preset!";

	$user_create_param = array (
					"sok" => "ok",
					"preset" => $params["configoption1"],
					"name" => $service_username,
					"ftp_user_name" => $service_username,
					"passwd" => $params["password"],
					"fullname" => $params["clientsdetails"]["lastname"]." ".$params["clientsdetails"]["firstname"],
					);

	$preset_param = ispmgr_api_request($server_ip, $server_username, $server_password, "preset.edit", array("elid" => $params["configoption1"],));
	$find_preset_param = $preset_param->xpath("/doc/*");
	foreach($find_preset_param as $param) {
		if (strpos($param->getName(), "limit_") === 0) {
			$user_create_param[$param->getName()] = (string)$param;
		}
	}

	if (array_key_exists("domain", $params) && $params["domain"] != "") {
		$domain_list = ispmgr_api_request($server_ip, $server_username, $server_password, "webdomain", array());
		$find_domain = $domain_list->xpath("/doc/elem[name='".$params["domain"]."']");
		$domain_name = $find_domain[0]->name;

		if ($domain_name != "") {
			$user_create_param["webdomain"] = "off";
			$user_create_param["emaildomain"] = "off";
		} else {
			$domain_list = ispmgr_api_request($server_ip, $server_username, $server_password, "emaildomain", array());
			$find_domain = $domain_list->xpath("/doc/elem[name='".$params["domain"]."']");
			$domain_name = $find_domain[0]->name;

			if ($domain_name != "") {
				$user_create_param["webdomain"] = "off";
				$user_create_param["emaildomain"] = "off";
			} else {
				$domain_list = ispmgr_api_request($server_ip, $server_username, $server_password, "domain", array());
				$find_domain = $domain_list->xpath("/doc/elem[name='".$params["domain"]."']");
				$domain_name = $find_domain[0]->name;

				if ($domain_name != "") {
					$user_create_param["webdomain"] = "off";
					$user_create_param["emaildomain"] = "off";
				} else {
					$user_create_param["webdomain_name"] = $params["domain"];
					$user_create_param["emaildomain_name"] = $params["domain"];
				}
			}
		}
	} else {
		$user_create_param["webdomain"] = "off";
		$user_create_param["emaildomain"] = "off";
	}

	if (array_key_exists("configoption2", $params) && $params["configoption2"] != "") {
		$user_create_param["limit_quota"] = $params["configoption2"];
	}

	if (array_key_exists("configoption3", $params) && $params["configoption3"] != "") {
		$user_create_param["limit_traff"] = $params["configoption3"];
	}

	if (array_key_exists("configoption4", $params) && $params["configoption4"] != "") {
		$user_create_param["limit_db"] = $params["configoption4"];
	}

	if (array_key_exists("configoption5", $params) && $params["configoption5"] != "") {
		$user_create_param["limit_db_users"] = $params["configoption5"];
	}

	if (array_key_exists("configoption6", $params) && $params["configoption6"] != "") {
		$user_create_param["limit_ftp_users"] = $params["configoption6"];
	}

	if (array_key_exists("configoption7", $params) && $params["configoption7"] != "") {
		$user_create_param["limit_webdomains"] = $params["configoption7"];
	}

	if (array_key_exists("configoption8", $params) && $params["configoption8"] != "") {
		$user_create_param["limit_emaildomains"] = $params["configoption8"];
	}

	if (array_key_exists("configoption9", $params) && $params["configoption9"] != "") {
		$user_create_param["limit_emails"] = $params["configoption9"];
	}

	if (array_key_exists("configoption10", $params) && $params["configoption10"] != "") {
		$user_create_param["limit_cpu"] = $params["configoption10"];
	}

	if (array_key_exists("configoption11", $params) && $params["configoption11"] != "") {
		$user_create_param["limit_memory"] = $params["configoption11"];
	}

	if (array_key_exists("configoption12", $params) && $params["configoption12"] != "") {
		$user_create_param["limit_process"] = $params["configoption12"];
	}

	if (array_key_exists("configoption13", $params) && $params["configoption13"] != "") {
		$user_create_param["limit_email_quota"] = $params["configoption13"];
	}

	$save_ip = false;
	$ip_count = 0;
	$ipv6_count = 0;

	if ($params["configoption14"] == "ipv4") {
		$ip_count = -1;
		$user_create_param["ipsrc"] = "auto";
		$user_create_param["iptype"] = "ipv4";
		$save_ip = true;
	} else if ($params["configoption14"] == "ipv6") {
		$ipv6_count = -1;
		$user_create_param["ipsrc"] = "auto";
		$user_create_param["iptype"] = "ipv6";
		$save_ip = true;
	} else {
		$user_create_param["ipsrc"] = "do_not_assign";
	}

	if (array_key_exists("IP", $params["configoptions"])) {
		$ip_count += $params["configoptions"]["IP"];
	}

	if (array_key_exists("IPv6", $params["configoptions"])) {
		$ipv6_count += $params["configoptions"]["IPv6"];
	}

	$user_create = ispmgr_api_request($server_ip, $server_username, $server_password, "user.add.finish", $user_create_param);

	$error = ispmgr_find_error($user_create);

	if ($error != "") {
		return $error;
	}

	$user_ip = ispmgr_api_request($server_ip, $server_username, $server_password, "ipaddr", array("su" => $service_username,));

	$main_ip = "0.0.0.0";

	if ($save_ip) {
		$find_ip = $user_ip->xpath("/doc/elem[iprole='assigned']");
		$main_ip = $find_ip[0]->name;
	} else {
		$find_ip = $user_ip->xpath("/doc/elem");
		$main_ip = $find_ip[0]->name;
	}

	update_query("tblhosting", array("dedicatedip" => $main_ip), array("id" => $params["serviceid"]));

	if ($ip_count > 0 || $ipv6_count > 0) {
		$ip_list = "";

		while ($ip_count > 0) {
			$new_ip_param = array(
						"sok" => "ok",
						"iprole" => "assigned",
						"iptype" => "ipv4",
						"name" => "",
						"owner" => $service_username,
					);

			$ip_add = ispmgr_api_request($server_ip, $server_username, $server_password, "ipaddr.edit", $new_ip_param);
			$find_ip = $ip_add->xpath("//id");
			$ip_list .= (string)$find_ip[0]."\n";
			$ip_count--;
		}

		while ($ipv6_count > 0) {
			$new_ip_param = array(
						"sok" => "ok",
						"iprole" => "assigned",
						"iptype" => "ipv6",
						"name" => "",
						"owner" => $service_username,
					);

			$ip_add = ispmgr_api_request($server_ip, $server_username, $server_password, "ipaddr.edit", $new_ip_param);
			$find_ip = $ip_add->xpath("//id");
			$ip_list .= (string)$find_ip[0]."\n";
			$ipv6_count--;
		}

		update_query("tblhosting", array("assignedips" => $ip_list), array("id" => $params["serviceid"]));
	}

	return "success";
}

function ispmgr_process_operation($func, $params) {
	$id = ispmgr_get_external_id($params);
	if ($id == "") {
			return "Unknown user!";
	}

	$server_ip = $params["serverip"];
	$server_username = $params["serverusername"];
	$server_password = $params["serverpassword"];

	$result = ispmgr_api_request($server_ip, $server_username, $server_password, $func, array("elid" => $id));
	$error = ispmgr_find_error($result);

	if ($error != "") {
			return "Error";
	}

	return "success";
}

function ispmanager_TerminateAccount($params) {
	global $op;
	$op = "terminate";
	return ispmgr_process_operation("user.delete", $params);
}

function ispmanager_SuspendAccount($params) {
	global $op;
	$op = "suspend";
	return ispmgr_process_operation("user.suspend", $params);
}

function ispmanager_UnsuspendAccount($params) {
	global $op;
	$op = "unsuspend";
	return ispmgr_process_operation("user.resume", $params);
}

function ispmanager_ChangePassword($params) {
	global $op;
	$op = "change password";
	$server_ip = $params["serverip"];
	$server_username = $params["serverusername"];
	$server_password = $params["serverpassword"];

	$id = ispmgr_get_external_id($params);

	if ($id == "")
		return "Unknown user!";

	$change_password = ispmgr_api_request($server_ip, $server_username, $server_password, "usrparam", array("su" => $id,
																										"passwd" => $params["password"],
																										"sok" => "ok"));
	$error = ispmgr_find_error($change_password);
	if ($error != "") {
			return $error;
	}

	return "success";
}





function ispmanager_ChangePackage($params) {
	global $op;
	$op = "change package";
	$server_ip = $params["serverip"];
	$server_username = $params["serverusername"];
	$server_password = $params["serverpassword"];

	$id = ispmgr_get_external_id($params);

	if ($id == "")
		return "Unknown user!";

	$user_edit_param = array (
					"sok" => "ok",
					"preset" => $params["configoption1"],
					"elid" => $id,
					);

	if (array_key_exists("configoption2", $params) && $params["configoption2"] != "") {
		$user_edit_param["limit_quota"] = $params["configoption2"];
	}

	if (array_key_exists("configoption3", $params) && $params["configoption3"] != "") {
		$user_edit_param["limit_traff"] = $params["configoption3"];
	}

	if (array_key_exists("configoption4", $params) && $params["configoption4"] != "") {
		$user_edit_param["limit_db"] = $params["configoption4"];
	}

	if (array_key_exists("configoption5", $params) && $params["configoption5"] != "") {
		$user_edit_param["limit_db_users"] = $params["configoption5"];
	}

	if (array_key_exists("configoption6", $params) && $params["configoption6"] != "") {
		$user_edit_param["limit_ftp_users"] = $params["configoption6"];
	}

	if (array_key_exists("configoption7", $params) && $params["configoption7"] != "") {
		$user_edit_param["limit_webdomains"] = $params["configoption7"];
	}

	if (array_key_exists("configoption8", $params) && $params["configoption8"] != "") {
		$user_edit_param["limit_emaildomains"] = $params["configoption8"];
	}

	if (array_key_exists("configoption9", $params) && $params["configoption9"] != "") {
		$user_edit_param["limit_emails"] = $params["configoption9"];
	}

	if (array_key_exists("configoption10", $params) && $params["configoption10"] != "") {
		$user_edit_param["limit_cpu"] = $params["configoption10"];
	}

	if (array_key_exists("configoption11", $params) && $params["configoption11"] != "") {
		$user_edit_param["limit_memory"] = $params["configoption11"];
	}

	if (array_key_exists("configoption12", $params) && $params["configoption12"] != "") {
		$user_edit_param["limit_process"] = $params["configoption12"];
	}

	if (array_key_exists("configoption13", $params) && $params["configoption13"] != "") {
		$user_edit_param["limit_email_quota"] = $params["configoption13"];
	}

	$change_package = ispmgr_api_request($server_ip, $server_username, $server_password, "user.edit", $user_edit_param);
	$error = ispmgr_find_error($change_package);
	if ($error != "") {
			return $error;
	}

	return "success";
}

function ispmanager_UsageUpdate($params) {
	global $op;
	$op = "usage";

	$serverid = $params['serverid'];
	$server_ip = $params['serverip'];
	$server_username = $params['serverusername'];
	$server_password = $params['serverpassword'];

	$result = select_query("tblhosting","tblhosting.id,mod_ispsystem.external_id",array("tblhosting.server" => $serverid),"","","","mod_ispsystem on mod_ispsystem.serviceid = tblhosting.id");

	if (!$result)
		logActivity("ISPmanager get stats error: ".mysql_error());
}

function ispmanager_ClientArea($params) {
	global $op;
	$op = "client area";
	$code = "";

	if ($_POST["process_ispmanager"] == "true") {
		$server_ip = $params["serverip"];
		$server_username = $params["serverusername"];
		$server_password = $params["serverpassword"];

		$key = md5(time()).md5($params["username"]);
		$newkey = ispmgr_api_request($server_ip, $server_username, $server_password, "session.newkey", array("username" => $params["username"],
																										   	"key" => $key));
		$error = ispmgr_find_error($newkey);
		if ($error != "") {
				return "";
		}

		$code = "<form action='https://".$params["serverip"]."/ispmgr' method='post' name='isplogin'>
				<input type='hidden' name='func' value='auth' />
				<input type='hidden' name='username' value='".$params["username"]."' />
				<input type='hidden' name='checkcookie' value='no' />
				<input type='hidden' name='key' value='".$key."' />
				<input type='submit' value='Login to Control Panel' class='button'/>
				</form>
				<script language='JavaScript'>document.isplogin.submit();</script>";
	} else {
		$code = "<form action='clientarea.php' method='post' target='_blank'>
			<input type='hidden' name='action' value='productdetails' />
			<input type='hidden' name='id' value='".$params["serviceid"]."' />
			<input type='hidden' name='process_ispmanager' value='true' />
			<input type='submit' value='Login to Control Panel' class='button'/>
			</form>";
	}

	return $code;
}

function ispmanager_AdminLink($params) {
		global $op;
		$op = "client area";
		$code = "";
		if ($_POST["process_ispmanager"] == "true" && $params["serverip"] == $_POST["process_ip"]) {
				$server_ip = $params["serverip"];
				$server_username = $params["serverusername"];
				$server_password = $params["serverpassword"];

				$key = md5(time()).md5($params["username"]);
				$newkey = ispmgr_api_request($server_ip, $server_username, $server_password, "session.newkey", array("username" => $server_username,
																													 "key" => $key));
				$error = ispmgr_find_error($newkey);
				if ($error != "") {
						return $error;
				}

				$code = "<form action='https://".$params["serverip"]."/ispmgr' method='post' name='isplogin'>
						<input type='hidden' name='func' value='auth' />
						<input type='hidden' name='username' value='".$server_username."' />
						<input type='hidden' name='checkcookie' value='no' />
						<input type='hidden' name='key' value='".$key."' />
						<input type='submit' value='ISPmanager' class='button'/>
						</form>
						<script language='JavaScript'>document.isplogin.submit();</script>";
		} else {
				$code = "<form action='configservers.php' method='post' target='_blank'>
						<input type='hidden' name='process_ispmanager' value='true' />
						<input type='hidden' name='process_ip' value='".$params["serverip"]."' />
						<input type='submit' value='ISPmanager' class='button'/>
						</form>";
		}

		return $code;
}

?>
