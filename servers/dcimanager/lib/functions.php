<?php
/*
 * Проверяет наличие элемента в списке серверов,
 * соответствующего заданному фильтру.
 * @var $filter массив параметров для xpath
 * @var $params массив параметров текущей услуги
 */
function HasItems($filter, $params) {
    $server = new Server($params);
    $serverXml = $server->apiRequest("server");

	$xp = "/doc/elem";
 	foreach ($filter as $key => $val){
        if(substr($key, -1) === "/")
            $fstr .= "(".($val === "TRUE" ? "" : "not")."(".substr($key, 0, -1)."))";
        else
            $fstr .= "(".$key."='".$val."')";
        if(next($filter)) $fstr.= " and ";
    }
    $xp.= "[".$fstr."]";
    $findItem = $serverXml->xpath($xp);

    logModuleCall("dcimanager", "xpath", $xp, $findItem, $findItem);

    return count($findItem) > 0;
}

/*
 * Вейтер операций. Повторяет операцию $num раз пока не получит true
 * @out Возвращает true если получил от $func true и false если
 * провел все итерации и не получил true
 * @var $func функция для вызова в цикле
 * @var $filter/$param Параметры передаваемые в функцию
 * @var $num количество вызовов функции
 */
function OperationWaiter($func, $filter, $param, $num) {
    while ($num) {
        if ($func($filter, $param))
            return true;
        sleep(5);
        $num--;
    }
    return false;
}

