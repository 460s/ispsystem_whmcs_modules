<?php
/*
 *  Module Version: 7.0.0
 */

function billmanager_noc_addon_MetaData()
{
	return [
		'DisplayName' => 'BILLmanager NOC addon',
	];
}

function billmanager_noc_addon_ConfigOptions()
{
	return [
		"license" => [
			"FriendlyName" => "License type",
			"Type" => "dropdown",
			"Options" => "ISPmanager 5 Lite,ISPmanager 5 Business,VMmanager 5 OVZ, VMmanager 5 KVM, VMmanager 5 Cloud",
			"Default" => "ISPmanager 5 Lite",
		],
		"package" => [
			"FriendlyName" => "Addon life time",
			"Type" => "text",
			"Size" => "32",
			"Description" => "month"
		]
	];
}

function billmanager_noc_addon_CreateAccount($params){
	return "success";
}

function billmanager_noc_addon_SuspendAccount($params){
	return "success";
}

function billmanager_noc_addon_UnsuspendAccount($params){
	return "success";
}

function billmanager_noc_addon_TerminateAccount($params){
	return "success";
}

