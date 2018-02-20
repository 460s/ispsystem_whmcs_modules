<?php

define("MGR", "/dcimgr");

class Server
{
	private $ip;
	private $root_name;
	private $password;
	private $user_name;
	private $user_password;

	function __construct($params)
	{
		$this->ip = $params["serverip"];
		$this->root_name = $params["serverusername"];
		$this->password = $params["serverpassword"];
		$this->user_name = $params["username"];
		$this->user_password = $params["password"];
	}

	public function AuthRequest($func, $auth, $param = [])
	{
		$param["auth"] = $auth;
		return $this->ApiRequest($func, $param);
	}

	/**
	 * Обертка над основным методом обращения к панели
	 * @return  object
	 */
	public function AuthInfoRequest($func, $param = [])
	{
		$param["authinfo"] = $this->root_name . ":" . $this->password;
		return $this->ApiRequest($func, $param);
	}

	public function GetAuth(&$key)
	{
		$func = "auth";
		$param = ["username" => $this->user_name, "key" => $key];
		$authinfo = $this->ApiRequest($func, $param);
		return $authinfo->auth;
	}

	public function GetSessionId()
	{
		$func = "auth";
		$param = ["username" => $this->user_name, "password" => $this->user_password];
		$authinfo = $this->ApiRequest($func, $param);
		return $authinfo->auth;
	}

	public function errorCheck(&$xml)
	{
		if ($xml->error)
			$error = $xml->error["type"] . ":" . $xml->error->msg;
		return $error;
	}

	private function ApiRequest(&$func, &$param = [])
	{
		global $op;

		$default_xml_error_string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<doc><error type=\"curl\"/></doc>\n";
		$url = "https://" . $this->ip . MGR;
		$postfields = ["out" => "xml", "func" => $func];
		$options = ['CURLOPT_TIMEOUT' => '60'];

		foreach ($param as &$value)
			$value = (string)$value;

		$response = curlCall($url, array_merge($postfields, $param), $options);

		logModuleCall("dcimanager:" . $func, $op, array_merge($postfields, $param), $response, $response, [$this->password]);

		try {
			$out = new SimpleXMLElement($response);
		} catch (Exception $e) {
			$out = simplexml_load_string($default_xml_error_string);
			$out->error->addChild("msg", $e->getMessage());
		}

		return $out;
	}
}


