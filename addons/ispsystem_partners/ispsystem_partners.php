<?php
/**
 * ISPsystem Addon Module
 *
 *
 * @package	ISPsystem
 * @author	ISPsystem LLC <support@ispsystem.com>
 * @copyright	Copyright (c) ISPsystem LLC 2016
 * @version    $Id$
 * @link       http://www.ispsystem.com/
 */

if (!defined("WHMCS"))
    die("This file cannot be accessed directly");

use WHMCS\Database\Capsule as DB;

function ispsystem_partners_config() {
	$configarray = array(
		"name" => "ISPsystem NOC Partner module",
		"description" => "Module for ISPsystem partners - ISPmanager and VMmanager license integration",
		"version" => "1.1",
		"author" => "ISPsystem LLC",
		"language" => "english",
		"fields" => array(),
		);
    return $configarray;
}
/* Создаем тариф через API WHMCS
	order - сортировка тарифов
	name - название тарифа
	desc - описание тарифа
	price - цена за 1 месяц
	pgid - id группы тарифов
	server_id - id сервера обработки
	server_gid - id группы серверов обработки
	price_id - id тарифа в биллинге ISPsystem
	addon_id - id дополнения в биллинге ISPsystem
	addon_price - цена дополнения в месяц
*/
function ispsystem_partners_create_packages($order,$name,$desc,$price,$pgid,$server_id,$server_gid,$price_id,$addon_id = '',$addon_price = ''){
	// Создадим тариф
	localAPI('addproduct'
		,array('type' => 'other'
			, 'gid' => $pgid
			, 'name' => $name
			, 'description' => $desc
			, 'paytype' => 'recurring'
			, 'autosetup' => 'payment'
			, 'module' => 'billmanager_noc'
			, 'pricing' => array('1' => array(
				'monthly' => $price
				, 'quarterly' => '-1.00'
				, 'semiannually' => '-1.00'
				, 'annually' => '-1.00'
				, 'biennially' => '-1.00'
				, 'triennially' => '-1.00'
				)
			)
			, 'configoption1' => $price_id
			, 'configoption2' => $addon_id
			, 'servergroupid' => $server_gid
			, 'order' => $order
		)
	);

	// Найдем id тарифа, который создали
    $result = DB::table('tblproducts')->select('id')->orderBy('id', 'desc')->first();
    $package_id = $result->id;

	// Добавим опцию ip
    DB::table('tblcustomfields')->insert([
        'type' => 'product',
        'relid' => $package_id,
        'fieldname' => 'ip',
        'description' => 'IP address for the license',
        'regexpr' => '/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/',
        'required' => 'on',
        'showorder' => 'on',
        'fieldtype' => 'text'
    ]);

	// Добавим опцию name
    DB::table('tblcustomfields')->insert([
        'type' => 'product',
		'relid' => $package_id,
		'fieldname' => 'name',
		'regexpr' => '/.+/',
		'description' => 'License name',
		'required' => 'on',
		'showorder' => 'on',
		'fieldtype' => 'text'
    ]);

	// Добавим опцию lickey
    DB::table('tblcustomfields')->insert([
        'type' => 'product',
		'relid' => $package_id,
		'fieldname' => 'lickey',
		'description' => 'License key',
		'fieldtype' => 'text'
    ]);

	// Создадим группы опций и опции для дозаказа дополнений
	if ($addon_id != '') {

        $gid = DB::table('tblproductconfiggroups')->insertGetId(['name' => $name . ' options group']);

        $option_id = DB::table('tblproductconfigoptions')->insertGetId([
            'gid' => $gid,
			'optionname' => 'Additional nodes in cluster',
			'optiontype' => '4',
			'qtyminimum' => 0,
			'qtymaximum' => 255
         ]);

        $option_sub_id = DB::table('tblproductconfigoptionssub')->insertGetId(['configid' => $option_id, 'optionname' => 'servers']);

        DB::table('tblpricing')->insert([
            'type' => 'configoptions',
            'currency' => 1,
            'relid' => $option_sub_id,
            'msetupfee' => '0.00',
            'qsetupfee' => '0.00',
            'ssetupfee' => '0.00',
            'asetupfee' => '0.00',
            'bsetupfee' => '0.00',
            'tsetupfee' => '0.00',
            'quarterly' => '0.00',
            'semiannually' => '0.00',
            'annually' => '0.00',
            'biennially' => '0.00',
            'triennially' => '0.00',
            'monthly' => $addon_price
        ]);

        DB::table('tblproductconfiglinks')->insert(['gid' => $gid, 'pid' => $package_id]);
	}

	// Создадим аддоны для остальных тарифов
    $new_addon_id = DB::table('tbladdons')->insertGetId([
        'name' => $name,
		'description' => $desc,
		'billingcycle' => 'Monthly',
		'showorder' => 'on',
		'autoactivate' => 'on'
     ]);
	// Выставляем цену аддона
    DB::table('tblpricing')->insert([
        'type' => 'addon',
		'currency' => 1,
		'relid' => $new_addon_id,
		'msetupfee' => '0.00',
		'qsetupfee' => '0.00',
		'ssetupfee' => '0.00',
		'asetupfee' => '0.00',
		'bsetupfee' => '0.00',
		'tsetupfee' => '0.00',
		'quarterly' => '0.00',
		'semiannually' => '0.00',
		'annually' => '0.00',
		'biennially' => '0.00',
		'triennially' => '0.00',
		'monthly' => $price
     ]);
	// Запомним id аддона, чтобы потом обрабатывать его автоматически
    DB::table('ispsystem_noc_addon')->insert([
        'addonid' => $new_addon_id,
		'priceid' => $price_id,
		'serverid' => $server_id,
		'priceaddon' => $addon_id
     ]);

}

function ispsystem_partners_activate() {
	// Создаем базу для хранения лицензий
	$query = "CREATE TABLE ispsystem_noc(
		id INT(11) NOT NULL AUTO_INCREMENT
		, licenseid INT(11) NOT NULL
		, licensetype INT(1) NOT NULL
		, serviceid INT(11) DEFAULT NULL
		, serverid  INT(11) NOT NULL
		, duedate DATE NOT NULL
		, servicepackage INT(11) DEFAULT NULL
		, serviceaddon INT(11) DEFAULT NULL
		, PRIMARY KEY (id))
		ENGINE=InnoDB DEFAULT CHARSET=utf8";
	$result = full_query($query);

	// Создаем базу для хранения id аддонов
	$query = "CREATE TABLE ispsystem_noc_addon(
		id INT(11) NOT NULL AUTO_INCREMENT
		, addonid INT(11) NOT NULL
		, priceid INT(11) NOT NULL
		, priceaddon INT(11) DEFAULT NULL
		, serverid INT(11) NOT NULL
		, PRIMARY KEY (id))
		ENGINE=InnoDB DEFAULT CHARSET=utf8";
	$result = full_query($query);

	// Создаем группу серверов
    $server_group_id = DB::table('tblservergroups')->insertGetId(['name' => 'BILLmanager NOC', 'filltype' => 1]);

	// Создаем сервер
    $server_id  = DB::table('tblservers')->insertGetId([
        'name' => 'ISPsystem NOC',
        'hostname' => 'https://api.ispsystem.com/manager/billmgr',
		'secure' => 'on',
		'type' => 'billmanager_noc',
		'active' => 1,
		'disabled' => 0
     ]);

	// Привяжем сервер к группе
    DB::table('tblservergroupsrel')->insert(['groupid' => $server_group_id, 'serverid' => $server_id]);

	// Создаем группу тарифов
    $product_group_id  = DB::table('tblproductgroups')->insertGetId([
        'name' => 'ISPsystem Software',
		'orderfrmtpl' => 'comparison'
     ]);

	// Создаем тарифы
	ispsystem_partners_create_packages(1,'ISPmanager 5 Lite','Control panel for managing your personal server','4.00',$product_group_id,$server_id,$server_group_id,'3541');
	ispsystem_partners_create_packages(2,'ISPmanager 5 Business','Control panel for providing shared and reselling hosting','12.00',$product_group_id,$server_id,$server_group_id,'4601','4602','12.00');
	ispsystem_partners_create_packages(3,'VMmanager 5 OVZ','OpenVZ-based virtual machines management','8.00',$product_group_id,$server_id,$server_group_id,'3651','3698','8.00');
	ispsystem_partners_create_packages(4,'VMmanager 5 KVM','KVM-based virtual machines management','8.00',$product_group_id,$server_id,$server_group_id,'3045','3049','8.00');
	ispsystem_partners_create_packages(5,'VMmanager 5 Cloud','Manage fail-over cluster of servers','80.00',$product_group_id,$server_id,$server_group_id,'3887','3889','16.00');

	return array('status'=>'success','description'=>'Module activated');
}

function ispsystem_partners_deactivate() {
    #Возвращаем результат
	return array('status'=>'success','description'=>'Module deactivated. Please delete packages\addons\servers manually');
}

?>
