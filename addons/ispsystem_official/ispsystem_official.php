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

use WHMCS\Database\Capsule as DB;

if (!defined("WHMCS"))
    die("This file cannot be accessed directly");

function ispsystem_official_config() {
	$configarray = array(
		"name" => "ISPsystem global module",
		"description" => "Common module for integration with ISPsystem control panels",
		"version" => "1.1",
		"author" => "ISPsystem LLC",
		"language" => "english",
		"fields" => array(),
		);
    return $configarray;
}

function ispsystem_official_activate() {
	if (DB::schema()->hasTable('mod_ispsystem'))
		return ['status'=>'error','description'=>'Table mod_ispsystem exists'];

	try {
		DB::schema()->create('mod_ispsystem', function ($table) {
				$table->increments('id');
				$table->integer('serviceid');
				$table->integer('external_id');
				$table->string('label')->nullable();
				$table->string('ipmi_ip')->nullable();
				$table->string('switch_port')->nullable();
		});
	} catch (Exception $e) {
		echo "Unable to create my_table: {$e->getMessage()}";
	}

	return ['status'=>'success','description'=>'Module actevated'];
}

function ispsystem_official_deactivate() {
	return ['status'=>'success','description'=>'Module deactevated'];
}

function ispsystem_official_upgrade($vars) {
	$version = $vars['version'];

	if ($version < 1.1) {
		DB::schema()->table('mod_ispsystem', function ($table) {
			$table->string('label')->nullable();
			$table->string('ipmi_ip')->nullable();
			$table->string('switch_port')->nullable();
		});
	}
}
?>
