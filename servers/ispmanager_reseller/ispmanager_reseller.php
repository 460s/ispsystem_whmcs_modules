<?php
use WHMCS\Database\Capsule as DB;

function ispmanager_reseller_MetaData(){
    return [
        'DisplayName' => 'ISPmanager Reseller',
        'RequiresServer' => true,
    ];
}

function ispmanager_reseller_ConfigOptions() {
    return [
        "package" => [
            "FriendlyName" => "Reseller Template",
            "Type" => "text",
            "Size" => "25",
        ],
        "limit_users" => [
            "FriendlyName" => "Users",
            "Type" => "text",
            "Size" => "8",
            "Description" => "pcs",
        ],
        "limit_resellertechdomain" => [
            "FriendlyName" => "Technical domains",
            "Type" => "text",
            "Size" => "8",
            "Description" => "pcs",
        ],
        "limit_ipv4addrs" => [
            "FriendlyName" => "IPv4 addresses",
            "Type" => "text",
            "Size" => "8",
            "Description" => "pcs",
        ],
        "limit_ipv6addrs" => [
            "FriendlyName" => "IPv6 addresses",
            "Type" => "text",
            "Size" => "8",
            "Description" => "pcs",
        ],
        "limit_quota" => [
            "FriendlyName" => "Disk",
            "Type" => "text",
            "Size" => "8",
            "Description" => "MiB",
        ],
        "limit_traff" => [
            "FriendlyName" => "Traffic",
            "Type" => "text",
            "Size" => "8",
            "Description" => "MiB per calendar month",
        ],
        "limit_ftp_users" => [
            "FriendlyName" => "FTP users",
            "Type" => "text",
            "Size" => "8",
            "Description" => "pcs",
        ],
        "limit_webdomains" => [
            "FriendlyName" => "WWW-domains",
            "Type" => "text",
            "Size" => "8",
            "Description" => "pcs",
        ],
        "limit_domains" => [
            "FriendlyName" => "Domain names",
            "Type" => "text",
            "Size" => "8",
            "Description" => "pcs",
        ],
        "limit_emaildomains" => [
            "FriendlyName" => "Mail domains",
            "Type" => "text",
            "Size" => "8",
            "Description" => "pcs",
        ],
        "limit_emails" => [
            "FriendlyName" => "Mailboxes",
            "Type" => "text",
            "Size" => "8",
            "Description" => "pcs",
        ],
        "limit_cpu" => [
            "FriendlyName" => "CPU time",
            "Type" => "text",
            "Size" => "8",
            "Description" => ".",
        ],
        "limit_memory" => [
            "FriendlyName" => "RAM",
            "Type" => "text",
            "Size" => "8",
            "Description" => "MiB",
        ],
        "limit_process" => [
            "FriendlyName" => "User processes",
            "Type" => "text",
            "Size" => "8",
            "Description" => "pcs",
        ],
        "limit_email_size" => [
            "FriendlyName" => "Mailbox maximum size",
            "Type" => "text",
            "Size" => "8",
            "Description" => "MiB",
        ],
        "limit_email" => [
            "FriendlyName" => "Email limit",
            "Type" => "text",
            "Size" => "8",
            "Description" => "from each user's mailbox per hour",
        ],        
        "limit_cron_jobs" => [
            "FriendlyName" => "Cron jobs",
            "Type" => "text",
            "Size" => "8",
            "Description" => "pcs",
        ],
        "limit_connections" => [
            "FriendlyName" => "Simultaneous connections per session",
            "Type" => "text",
            "Size" => "8",
            "Description" => "one IP address",
        ],
        "limit_apache_handlers" => [
            "FriendlyName" => "Apache handlers",
            "Type" => "text",
            "Size" => "8",
            "Description" => "per each WWW-domain",
        ],        
        "username_template" => [
            "FriendlyName" => "Username template",
            "Type" => "text",
            "Size" => "32",
            "Description" => "<br/><br/>@ID@ - service id<br/>@DOMAIN@ - domain name",
        ],
    ];
}
function isp_array_assembly($params) {
    $server_username = $params["serverusername"];
    $server_password = $params["serverpassword"];
    $server_ip = $params["serverip"];
    
    if (!empty($params["configoption1"])){
        $preset_list = isp_api_request($server_ip, $server_username, $server_password, "preset", array());
        $find_preset = $preset_list->xpath("/doc/elem[name='".$params["configoption1"]."']");
        $preset_name = $find_preset[0]->name;
        if (empty($preset_name)) return "Can not find preset!";
        $arr["preset"] = $params["configoption1"];
                
        $preset_param = isp_api_request($server_ip, $server_username, $server_password, "preset.edit", array("elid" => $params["configoption1"],));
        $find_preset_param = $preset_param->xpath("/doc/*");
        foreach($find_preset_param as $param) {
                if (strpos($param->getName(), "limit_") === 0) {
                        $arr[$param->getName()] = (string)$param;
                }
        }
    }
    
    if (!empty($params["configoption2"])) $arr["limit_users"] = $params["configoption2"];
    if (!empty($params["configoption3"])) $arr["limit_resellertechdomain"] = $params["configoption3"];
    if (!empty($params["configoption4"])) $arr["limit_ipv4addrs"] = $params["configoption4"];
    if (!empty($params["configoption5"])) $arr["limit_ipv6addrs"] = $params["configoption5"];
    if (!empty($params["configoption6"])) $arr["limit_quota"] = $params["configoption6"];
    if (!empty($params["configoption7"])) $arr["limit_traff"] = $params["configoption7"];
    if (!empty($params["configoption8"])) $arr["limit_ftp_users"] = $params["configoption8"];
    if (!empty($params["configoption9"])) $arr["limit_webdomains"] = $params["configoption9"];
    if (!empty($params["configoption10"])) $arr["limit_domains"] = $params["configoption10"];
    if (!empty($params["configoption11"])) $arr["limit_emaildomains"] = $params["configoption11"];
    if (!empty($params["configoption12"])) $arr["limit_emails"] = $params["configoption12"];
    if (!empty($params["configoption13"])) $arr["limit_cpu"] = $params["configoption13"];
    if (!empty($params["configoption14"])) $arr["limit_memory"] = $params["configoption14"];
    if (!empty($params["configoption15"])) $arr["limit_process"] = $params["configoption15"];
    if (!empty($params["configoption16"])) $arr["limit_email_quota"] = $params["configoption16"];
    if (!empty($params["configoption17"])) $arr["limit_mailrate"] = $params["configoption17"];
    if (!empty($params["configoption18"])) $arr["limit_scheduler"] = $params["configoption18"];
    if (!empty($params["configoption19"])) $arr["limit_nginxlimitconn"] = $params["configoption19"];  
    if (!empty($params["configoption20"])) $arr["limit_maxclientsvhost"] = $params["configoption20"]; 
    $arr["limit_brand_domain"] = !empty($params["domain"]) ? $params["domain"] : "";
    $arr["sok"] = "ok";
    
    return $arr;
}
function isp_api_request($ip, $username, $password, $func, $param) {
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

function isp_find_error($xml) {
    $error = "";
    if ($xml->error) 
        $error = $xml->error["type"].":".$xml->error->msg;

    return $error;
}

function ispmanager_reseller_GenerateUsername($params, $template) {
    $template = str_replace("@ID@", $params["serviceid"], $template);
    $template = str_replace("@DOMAIN@", $params["domain"], $template);

    $num = "";
    do{
        $template .= $num;
        $query = DB::table('tblhosting')
            ->select('username')
            ->where([
                ['username', $template],
                ['id', '!=', $params["serviceid"]]
            ])->get();
        $num++;
    }while(count($query) > 0);

    DB::table('tblhosting')->where('id', $params["serviceid"])->update(['username' => $template]);

    return $template;
}

function ispmanager_reseller_CreateAccount($params) {      
    global $op;
    $op = "create";
 
    $server_ip = $params["serverip"];
    if (empty($server_ip)) return "No server!";

    $server_username = $params["serverusername"];
    $server_password = $params["serverpassword"];
    $service_username = $params["username"];

    if (!empty($params["configoption21"]))
            $service_username = ispmanager_reseller_GenerateUsername($params, $params["configoption21"]);

    $user_list = isp_api_request($server_ip, $server_username, $server_password, "user", array());
    $find_user = $user_list->xpath("/doc/elem[level='16' and name='".$service_username."']");
    $user_name = $find_user[0]->name;
    if (!empty($user_name)) return "User with same username exists!";
    
 
    $user_create_param = [
        "name" => $service_username,
        "passwd" => $params["password"],
        "fullname" => $params["clientsdetails"]["lastname"]." ".$params["clientsdetails"]["firstname"],
    ];    
    $extra_param = isp_array_assembly($params); 
    
    $user_create = isp_api_request($server_ip, $server_username, $server_password, "reseller.edit", array_merge($user_create_param, $extra_param));
    $error = isp_find_error($user_create);

    return !empty($error) ? $error : "success";
}

function isp_process_operation($func, $params) {
    if (empty($params["username"]))	return "Unknown user!";

    $result = isp_api_request($params["serverip"], $params["serverusername"], $params["serverpassword"], $func, ["elid" => $params["username"]]);
    $error = isp_find_error($result);

    return !empty($error) ? $error : "success";
}

function ispmanager_reseller_TerminateAccount($params) {
    global $op;
    $op = "terminate";
    return isp_process_operation("reseller.delete", $params);
}

function ispmanager_reseller_SuspendAccount($params) {
    global $op;
    $op = "suspend";
    return isp_process_operation("reseller.suspend", $params);
}

function ispmanager_reseller_UnsuspendAccount($params) {
    global $op;
    $op = "unsuspend";
    return isp_process_operation("reseller.resume", $params);
}

function ispmanager_reseller_ChangePassword($params) {
    global $op;
    $op = "change password";

    if (empty($params["username"])) return "Unknown user!";

    $change_password = isp_api_request(
            $params["serverip"],
            $params["serverusername"],
            $params["serverpassword"],
            "usrparam",
            [
                "su" => $params["username"],
                "passwd" => $params["password"],
                "sok" => "ok"
            ]
    );
    $error = isp_find_error($change_password);

    return !empty($error) ? $error : "success";
}

function ispmanager_reseller_ChangePackage($params) {
    global $op;
    $op = "change package";
 
    if (empty($params["username"])) return "Unknown user!";

    $user_edit_param["elid"] = $params["username"];    
    $extra_param = isp_array_assembly($params);
    
    $change_package = isp_api_request(
            $params["serverip"],
            $params["serverusername"],
            $params["serverpassword"],
            "reseller.edit",
            array_merge($user_edit_param, $extra_param)
    );    
    $error = isp_find_error($change_package);
    
    return !empty($error) ? $error : "success";
}

function ispmanager_reseller_ClientArea($params) {
    global $op;
    $op = "client area";
    $code = "";

    if ($_POST["process_ispmanager"] == "true") {
        $server_ip = $params["serverip"];
        $server_username = $params["serverusername"];
        $server_password = $params["serverpassword"];

        $key = md5(time()).md5($params["username"]);
        $newkey = isp_api_request($server_ip, $server_username, $server_password, "session.newkey", array("username" => $params["username"],
                                                                                                                                                                                                                "key" => $key));
        $error = isp_find_error($newkey);
        if (!empty($error)) return $error;
 
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

function ispmanager_reseller_AdminLink($params) {
    global $op;
    $op = "client area";
    $code = "";
    if ($_POST["process_ispmanager"] == "true" && $params["serverip"] == $_POST["process_ip"]) {
        $server_ip = $params["serverip"];
        $server_username = $params["serverusername"];
        $server_password = $params["serverpassword"];

        $key = md5(time()).md5($params["username"]);
        $newkey = isp_api_request($server_ip, $server_username, $server_password, "session.newkey", array("username" => $server_username,
                                                                                                                                                                                                                 "key" => $key));
        $error = isp_find_error($newkey);
        if (!empty($error)) return $error;

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
