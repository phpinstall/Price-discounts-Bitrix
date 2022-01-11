<?php
/**
 * @author Anton SH <phpinstall@gmail.com>
 */

use ASH\Exchange\DiscountsExcel;

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
    $exchange = new DiscountsExcel();
    #$exchange->deleteAllRule(); //удаляет все созданные правила
    if ($exchange->execute())
        echo 'Success';
} catch (Throwable $e) {
    echo('Error: ' . $e->getMessage());
}