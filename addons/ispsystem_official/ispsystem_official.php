<?php
/**
 * ISPsystem Addon Module
 *
 *
 * @package	ISPsystem
 * @author	ISPsystem LLC <support@ispsystem.com>
 * @copyright	Copyright (c) ISPsystem LLC 2014
 * @version    $Id$
 * @link       http://www.ispsystem.com/
 */

if (!defined("WHMCS"))
    die("This file cannot be accessed directly");

function ispsystem_official_config() {
	$configarray = array(
		"name" => "ISPsystem global module",
		"description" => "Common module for integration with ISPsystem control panels",
		"version" => "1.0",
		"author" => "ISPsystem LLC",
		"language" => "english",
		"fields" => array(),
		);
    return $configarray;
}

function ispsystem_official_activate() {
	$query = "CREATE TABLE `mod_ispsystem` (`id` INT(11) NOT NULL AUTO_INCREMENT, serviceid INT(11) NOT NULL, `external_id` VARCHAR(255) DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8";
	$result = mysql_query($query);

	return array('status'=>'success','description'=>'Module actevated');
}

function ispsystem_official_deactivate() {
	return array('status'=>'success','description'=>'Module deactevated');
}

?>
