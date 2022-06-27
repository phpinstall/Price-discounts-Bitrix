<?php
/**
 * @author Anton SH <phpinstall@gmail.com>
 */

use ASH\Exchange\DiscountsExcel;
use ASH\Exchange\DiscountsExcelFloor;

const STOP_STATISTICS = true;
const NO_KEEP_STATISTIC = true;
const NO_AGENT_STATISTIC = true;
const NO_AGENT_CHECK = true;
const PERFMON_STOP = true;
const NOT_CHECK_PERMISSIONS = true;

if (empty($_SERVER["DOCUMENT_ROOT"]))
    $_SERVER["DOCUMENT_ROOT"] = realpath(__DIR__ . '../../../');
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
try {
    #$exchange = new DiscountsExcel();
    $exchange = new DiscountsExcelFloor();
    #$exchange->deleteAllRule(); //удаляет все созданные правила (применить 1 раз при изменении варианта)
    if ($exchange->execute())
        echo 'Success';
} catch (Throwable $e) {
    echo('Error: ' . $e->getMessage() . PHP_EOL . $e->getFile() . ':' . $e->getLine());
}