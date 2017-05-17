<?php
namespace dci;

class Server
{
    private $ip;
    private $username;
    private $password;

    function __construct($params) {
        $this->ip = $params["serverip"];
        $this->username = $params["serverusername"];
        $this->password = $params["serverpassword"];
    }

    public function apiRequest($func, $param = []) {
	global $op;

	$default_xml_error_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc><error type=\"curl\"/></doc>\n";

	$url = "https://".$this->ip."/dcimgr";
	$postfields = ["out" => "xml", "func" => $func, "authinfo" => $this->username.":".$this->password,];
	$options = array ('CURLOPT_TIMEOUT' => '60');
	foreach ($param as &$value) {
            $value = (string) $value;
        }

        $response = curlCall($url, array_merge($postfields, $param), $options);

	logModuleCall("dcimanager:".$func, $op, array_merge($postfields, $param), $response, $response, array ($this->password));

	try {
		$out = new SimpleXMLElement($response);
	} catch (Exception $e) {
		$out = simplexml_load_string($default_xml_error_string);
		$out->error->addChild("msg", $e->getMessage());
	}

	return $out;
    }
}


