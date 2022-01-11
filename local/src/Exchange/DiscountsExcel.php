<?php
/**
 * @author Anton SH <phpinstall@gmail.com>
 */

namespace ASH\Exchange;

use ASH\Services\Excel\Helper as ExcelHelper;
use Bitrix\Catalog\Model\Price;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Sale\Internals\DiscountTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

/**
 * Класс для синхронизации правил корзины с excel файлом;
 * одно правило на одно торговое предложение
 * значение прайса равно итоговой стоимости, размер скидки в процентах рассчитывается исходя из базовой цены на сайте.
 */
class DiscountsExcel
{
    /**
     * ID сайта
     *
     * @var string
     */
    private $siteID;

    /**
     * Шаблон названий правил работы корзин
     * 0 - префикс, 1 - префикс с id товара
     *
     * @var array|string[]
     */
    private $rulePatternName;

    /**
     * Соответствия столбцов
     *
     * @var array
     */
    private $mappingColumn = [];

    /**
     * Импортируемые данные
     *
     * @var array
     */
    private $importData = [];

    /**
     * Данные для отчёта
     *
     * @var array
     */
    private $report = [];

    /**
     * Соответствия артикула и идентификатора товаров
     * article -> bxId
     *
     * @var array
     */
    private $productsIdMap = [];

    /**
     * Расположение файлов
     *
     * @var array
     */
    private $files;

    /**
     * Код свойства артикула
     *
     * @var string
     */
    private $propertyArticle;

    /**
     * Символьный код инфоблока с торговым предложения
     *
     * @var string
     */
    private $ibOffersCode;

    /**
     * @throws LoaderException
     */
    public function __construct()
    {
        $this->siteID = \CSite::GetDefSite(); //сайт по умолчанию
        $this->rulePatternName = ['$AUTO_EXCEL', '$AUTO_EXCEL{%s}[%s]'];
        $this->files = [
            'importExel' => $_SERVER['DOCUMENT_ROOT'] . '/upload/exchange/import/autoImportRuleBasket.xlsx',
            'logFile' => $_SERVER['DOCUMENT_ROOT'] . '/upload/exchange/import/autoImportRuleBasket.txt'
        ];
        $this->propertyArticle = 'PROPERTY_ARTNUMBER';
        $this->ibOffersCode = 'clothes_offers';

        \CModule::IncludeModule('sale');
        Loader::includeModule('iblock');
    }

    /**
     * В любом случае пишем отчёт
     */
    public function __destruct()
    {
        $this->saveReport();
    }

    /**
     * Функция - контроллер обмена.
     * Импорт автоматически запускается только после изменения файла
     *
     * @return bool
     * @throws ArgumentException
     * @throws ArgumentOutOfRangeException
     * @throws LoaderException
     * @throws ObjectNotFoundException
     * @throws ObjectPropertyException
     * @throws SystemException
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    public function execute(): bool
    {
        if (!file_exists($this->files['importExel']))
            throw new \Exception('файл импорта отсутствует.');

        //запускать только после изменения файла со скидками
        $fileEditTime = filemtime($this->files['importExel']);
        if ($fileEditTime == Option::get('exchange', 'DiscountsExcel'))
            throw new \Exception('в файле импорта изменений нет');

        //получить данные из excel файла
        $excelData = $this->getDataFromExcelFile();
        //произвести валидацию, отфильтровать и синхронизировать данные
        $this->prepareData($excelData)->filter()->synchronizingRules();

        //очистить кэш каталога
        $this->clearCacheCatalog();

        //отметить последнее изменения файла
        Option::set('exchange', 'DiscountsExcel', $fileEditTime);

        return true;
    }

    /**
     * Экспортировать данные из файла с фильтрацией
     *
     * @return array|mixed
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    private function getDataFromExcelFile()
    {
        $params = [
            'columns' => range('A', 'F'),
            'startRow' => 2,
            'endRow' => 999999,
            'sheetName' => 'ИмпортПравилКорзины',
            'file' => $this->files['importExel'],
        ];
        $this->mappingColumn = [
            'A' => 'article',
            'B' => 'priceDiscount',
            'C' => 'dateTimeStart',
            'D' => 'dateTimeStop',
            'E' => 'categoryId',
            //'F' => 'name', //отладочный
        ];

        return ExcelHelper::read($params);
    }

    /**
     * Фильтрация и форматирование данных
     *
     * @param array $arData
     * @return DiscountsExcel
     */
    private function prepareData(array $arData): DiscountsExcel
    {
        $importData = [];
        //преобразовать столбцы в ассоциативный массив
        foreach ($arData as $keyRow => $row) {
            foreach ($row as $keyColumn => $column) {
                if (!empty($this->mappingColumn[$keyColumn])) {
                    $importData[$keyRow][$this->mappingColumn[$keyColumn]] = $column;
                }
            }
        }
        //отфильтровать нерелевантные строки и типизировать данные
        foreach ($importData as $key => &$row) {
            $oldRow = $row;
            if (!empty($row['article']) && (empty($row['priceDiscount']) || !is_numeric($row['priceDiscount']))) {
                $this->report[] = [
                    'type' => 'validation',
                    'text' => 'Некорректная или пустая цена: "' . $row['priceDiscount'] . '"; Обработка правила пропущена',
                    'product' => $row['article'],
                    'sort' => 113,
                    'indexRow' => $key
                ];
            }
            if (empty($row['article']) || empty($row['priceDiscount']) || !is_numeric($row['priceDiscount']))
                unset($importData[$key]);
            $row['indexRow'] = $key;
            $row['article'] = trim($row['article']);
            $row['priceDiscount'] = floatval($row['priceDiscount']);
            $row['dateTimeStart'] = \DateTime::createFromFormat('d/m/y H:i', $row['dateTimeStart']) ?: null;
            $row['dateTimeStop'] = \DateTime::createFromFormat('d/m/y H:i', $row['dateTimeStop']) ?: null;
            $row['categoryId'] = ctype_digit((string)$row['categoryId']) ? $row['categoryId'] : null;
            if ((isset($oldRow['dateTimeStart']) && !$row['dateTimeStart']) || (isset($oldRow['dateTimeStop']) && !$row['dateTimeStop'])) {
                $this->report[] = [
                    'type' => 'validation',
                    'text' => 'Некорректный формат даты "' . $oldRow['dateTimeStart'] . '" - "' . $oldRow['dateTimeStop'] . '"; Алгоритм продолжил обработку без заданного значения',
                    'product' => $row['article'],
                    'sort' => 112,
                    'indexRow' => $key
                ];
            }
            if ((isset($oldRow['categoryId']) && !$row['categoryId'])) {
                $this->report[] = [
                    'type' => 'validation',
                    'text' => 'Некорректный формат идентификатора категории "' . $oldRow['categoryId'] . '"; Алгоритм продолжил обработку без заданного значения',
                    'product' => $row['article'],
                    'sort' => 114,
                    'indexRow' => $row['indexRow']
                ];
            }
        }
        $this->importData = $importData;
        return $this;
    }

    /**
     * Правила фильтрации
     */
    private function filter(): DiscountsExcel
    {
        //отсеять неактивные по dateTimeStop;
        $this->importData = array_filter($this->importData, function ($value) {
            if (empty($value['dateTimeStop']))
                return true;
            return (new \DateTime() <= $value['dateTimeStop']);
        });

        //среди дубликатов по article отсеять те, у кого выше priceDiscount
        array_multisort(array_column($this->importData, 'priceDiscount'), SORT_ASC, $this->importData);
        $tempImportData = [];
        foreach ($this->importData as $key => $row) {
            if (array_key_exists($row['article'], $tempImportData))
                unset($this->importData[$key]);
            else
                $tempImportData[$row['article']] = $row['priceDiscount'];
        }
        array_multisort(array_column($this->importData, 'indexRow'), SORT_ASC, $this->importData);

        return $this;
    }

    /**
     * Синхронизация правил корзины с exel файлом. Добавление/обновление/удаление.
     *
     * @return $this
     * @throws ArgumentException
     * @throws LoaderException
     * @throws ObjectNotFoundException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function synchronizingRules(): DiscountsExcel
    {
        //Найти все существующие правила
        $dbResult = DiscountTable::getList(
            [
                'filter' => [
                    "LID" => $this->siteID,
                    "%NAME" => $this->rulePatternName[0]
                ],
                'select' => ['ID', 'NAME']
            ]
        );
        $dbIssetRules = $dbResult->fetchAll();
        $dbIssetRules = array_combine(array_column($dbIssetRules, 'ID'), array_column($dbIssetRules, 'NAME'));

        // найти основные ID товаров по "родительскому" ID из excel
        $resultOffers = \CIBlockElement::getList(
            ['SORT' => 'ASC'],
            [
                'IBLOCK_ID' => $this->getIbProducts(),
                $this->propertyArticle => array_column($this->importData, 'article'),
            ],
            false,
            false,
            [
                'ID',
                $this->propertyArticle,
            ]
        );
        while ($result = $resultOffers->fetch()) {
            $this->productsIdMap[$result[$this->propertyArticle . '_VALUE']] = $result['ID'];
        }

        // добавить/обновить правила
        $nameRuleDb = [];
        foreach ($this->importData as $itemRule) {
            if (empty($this->productsIdMap[$itemRule['article']])) {
                $this->report[] = [
                    'type' => 'notFound',
                    'text' => 'Ошибка. Товар не найден',
                    'product' => $itemRule['article'],
                    'sort' => 110,
                    'indexRow' => $itemRule['indexRow']
                ];
                continue;
            }
            $discount = 0;
            $productId = $this->productsIdMap[$itemRule['article']];
            $nameRule = sprintf($this->rulePatternName[1], $itemRule['article'], $productId);
            $nameRuleDb[] = $nameRule;
            $sort = $this->getRuleSort($itemRule, (int)$productId);
            $priority = $this->getRulePriorityAndDiscount($itemRule, (int)$productId, $discount);
            $dateTimeStart = $itemRule['dateTimeStart'] ? $itemRule['dateTimeStart']->format('d.m.Y H:i:s') : null;
            $dateTimeStop = $itemRule['dateTimeStop'] ? $itemRule['dateTimeStop']->format('d.m.Y H:i:s') : null;
            $priceDiscount = (float)$itemRule['priceDiscount'];

            if ($discount <= 0) {
                $this->report[] = [
                    'type' => 'notice',
                    'text' => 'Скидка добавлена/обновлена, но не применена: отрицательная или нулевая скидка: ' . $discount . '%',
                    'product' => $itemRule['article'],
                    'sort' => 200,
                    'indexRow' => $itemRule['indexRow']
                ];
            }

            if (!in_array($nameRule, $dbIssetRules)) {
                $resultId = $this->addRule(
                    (int)$productId,
                    $priceDiscount,
                    $nameRule,
                    $priority,
                    $sort,
                    'N',
                    'N',
                    [2],
                    $dateTimeStart,
                    $dateTimeStop
                );
                if ($resultId) {
                    $this->report[] = [
                        'type' => 'add',
                        'text' => 'Скидка добавлена. ID rule: ' . $resultId . '; productId: ' . $productId . ' priority: ' . $discount . '; discount: ' . $discount . '%',
                        'product' => $itemRule['article'],
                        'sort' => 700,
                        'indexRow' => $itemRule['indexRow']
                    ];
                } else {
                    $this->report[] = [
                        'type' => 'error',
                        'text' => 'Ошибка. Скидка не добавлена',
                        'product' => $itemRule['article'],
                        'sort' => 120,
                        'indexRow' => $itemRule['indexRow']
                    ];
                }
            } else {
                $ruleID = array_search($nameRule, $dbIssetRules);
                $result = $this->updateRule(
                    (int)$ruleID,
                    $priceDiscount,
                    $priority,
                    $sort,
                    $dateTimeStart,
                    $dateTimeStop
                );
                if ($result) {
                    $this->report[] = [
                        'type' => 'update',
                        'text' => 'Скидка обновлена. ID rule: ' . $ruleID . '; priority: ' . $priority . '; discount: ' . $discount . '%',
                        'product' => $itemRule['article'],
                        'sort' => 800,
                        'indexRow' => $itemRule['indexRow']
                    ];
                } else {
                    $this->report[] = [
                        'type' => 'error',
                        'text' => 'Ошибка. ID rule: ' . $ruleID . '; Скидка не обновлена',
                        'product' => $itemRule['article'],
                        'sort' => 130,
                        'indexRow' => $itemRule['indexRow']
                    ];
                }
            }
        }


        //Из старых правил выбираем те, которых нет в exel и удаляем
        $ruleDiff = array_diff($dbIssetRules, $nameRuleDb);
        foreach ($ruleDiff as $ruleID => $nameRule) {
            $result = $this->deleteRule($ruleID);
            if ($result) {
                $this->report[] = [
                    'type' => 'delete',
                    'text' => 'Скидка удалена. ID rule: ' . $ruleID . '; DiscountName: "' . $nameRule . '"',
                    'sort' => 900,
                    'indexRow' => null
                ];
            } else {
                $this->report[] = [
                    'type' => 'error',
                    'text' => 'Ошибка. ID rule: ' . $ruleID . '; Скидка не удалена, DiscountName: "' . $nameRule . '"',
                    'sort' => 140,
                    'indexRow' => null
                ];
            }
        }

        return $this;
    }

    /**
     * Индекс сортировки в уровне приоритета
     * В данном примере сортировка равняется целому значению прайса (*)
     *
     * @param array $itemRule
     * @param int $productId
     * @return int
     */
    private function getRuleSort(array $itemRule, int $productId): int
    {
        return (int)$itemRule['priceDiscount'];
    }

    /**
     * Приоритет применимости
     * В данном примере приоритет равняется целому проценту скидки, умноженному на 10 (*)
     *
     * @param array $itemRule
     * @param int $productId
     * @param float $discount
     * @return int
     * @throws ArgumentException
     * @throws ObjectNotFoundException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function getRulePriorityAndDiscount(array $itemRule, int $productId, float &$discount): int
    {
        static $basePrices = null;
        $basePrice = 0;

        if ($basePrices == null) {
            $basePrices = $this->getBasePrices(array_values($this->productsIdMap));
        }
        if (!empty($basePrices[$productId]) && $basePrices[$productId] > 0)
            $basePrice = (float)$basePrices[$productId];
        $discount = round(($basePrice - $itemRule['priceDiscount']) / $basePrice * 100, 2);
        $priority = (int)($discount * 10); //скидка округляется до целых математически, приоритет умножается на 10 и округляется в меньшую до целых
        return ($priority > 1) ? $priority : 1;
    }

    /**
     * Запрос базовых цен для товаров
     *
     * @param array $ids
     * @return array
     * @throws ArgumentException
     * @throws ObjectNotFoundException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function getBasePrices(array $ids): array
    {
        $iterator = Price::getList([
            'select' => ['PRODUCT_ID', 'PRICE'],
            'filter' => [
                '=PRODUCT_ID' => $ids,
            ],
        ]);
        $resultPrices = [];
        while ($row = $iterator->fetch())
            $resultPrices[$row['PRODUCT_ID']] = $row['PRICE'];
        return $resultPrices;
    }

    /**
     * Получить id инфоблока товаров
     *
     * @return int
     * @throws Exception
     */
    private function getIbProducts(): int
    {
        static $ibOffers = null;
        if ($ibOffers === null) {
            $ob = \CIBlock::GetList([], ['CODE' => $this->ibOffersCode, 'CHECK_PERMISSIONS' => 'N'], false)->GetNext();
            if (empty($ob['ID']) || !is_numeric($ob['ID'])) {
                throw new \Exception('Инфоблок торговых предложений не найден');
            }
            $ibOffers = (int)$ob['ID'];
        }
        return $ibOffers;
    }

    /**
     * Добавление правила корзины
     *
     * @param int $productId
     * @param float $priceDiscount
     * @param string $name
     * @param int|null $priority - Приоритет применимости
     * @param int|null $sort - Индекс сортировки в уровне приоритета
     * @param string|null $lastLevelDiscount - Прекратить применение скидок на текущем уровне приоритетов
     * @param string|null $lastDiscount - Прекратить дальнейшее применение правил
     * @param array|int[] $userGroups - пользовательские группы; 2 - все
     * @param string|null $dateTimeStart - начало активности
     * @param string|null $dateTimeStop - окончание активности
     * @return false|int
     */
    private function addRule(
        int $productId,
        float $priceDiscount,
        string $name,
        ?int $priority = null,
        ?int $sort = null,
        ?string $lastLevelDiscount = null,
        ?string $lastDiscount = null,
        ?array $userGroups = null,
        ?string $dateTimeStart = null,
        ?string $dateTimeStop = null
    ) {
        $priority = $priority ?? 1;
        $sort = $sort ?? 100;
        $lastLevelDiscount = $lastLevelDiscount ?? 'N';
        $lastDiscount = $lastDiscount ?? 'N';
        $userGroups = $userGroups ?? [2];

        $addRuleData = [
            'LID' => SITE_ID,
            "SITE_ID" => SITE_ID,
            'ACTIVE' => 'Y',
            'NAME' => $name,
            'SORT' => $sort,
            'PRIORITY' => $priority,
            'LAST_DISCOUNT' => $lastDiscount,
            'LAST_LEVEL_DISCOUNT' => $lastLevelDiscount,
            'USER_GROUPS' => $userGroups,
            'ACTIVE_FROM' => $dateTimeStart,
            'ACTIVE_TO' => $dateTimeStop,
            'CONDITIONS' => array(
                'CLASS_ID' => 'CondGroup',
                'DATA' =>
                    array(
                        'All' => 'AND',
                        'True' => 'True',
                    ),
                'CHILDREN' =>
                    array(),
            ),
            'ACTIONS' => array(
                'CLASS_ID' => 'CondGroup',
                'DATA' =>
                    array(
                        'All' => 'AND',
                    ),
                'CHILDREN' =>
                    array(
                        0 =>
                            array(
                                'CLASS_ID' => 'ActSaleBsktGrp',
                                'DATA' =>
                                    array(
                                        'Type' => 'Closeout',
                                        'Value' => $priceDiscount,
                                        'Unit' => 'CurEach',
                                        'Max' => 0,
                                        'All' => 'AND',
                                        'True' => 'True',
                                    ),
                                'CHILDREN' =>
                                    array(
                                        0 =>
                                            array(
                                                'CLASS_ID' => 'CondIBElement',
                                                'DATA' =>
                                                    array(
                                                        'logic' => 'Equal',
                                                        'value' => $productId,
                                                    ),
                                            ),
                                        1 =>
                                            array(
                                                'CLASS_ID' => 'CondBsktAppliedDiscount',
                                                'DATA' =>
                                                    array(
                                                        'value' => 'N',
                                                    ),
                                            ),
                                    ),
                            ),
                    ),
            )
        ];

        return \CSaleDiscount::Add($addRuleData);
    }

    /**
     * Обновление правила корзины
     *
     * @param int $ruleID - ID правила корзины
     * @param float $priceDiscount - прайс
     * @param int|null $priority - Приоритет применимости
     * @param int|null $sort - Индекс сортировки в уровне приоритета
     * @param string|null $dateTimeStart - начало активности
     * @param string|null $dateTimeStop - окончание активности
     * @return false|int
     */
    private function updateRule(
        int $ruleID,
        float $priceDiscount,
        ?int $priority = null,
        ?int $sort = null,
        ?string $dateTimeStart = null,
        ?string $dateTimeStop = null
    ) {
        $priority = $priority ?? 1;
        $sort = $sort ?? 100;

        $arUpdateActions = unserialize(\CSaleDiscount::GetByID($ruleID)['ACTIONS']);
        $arUpdateActions['CHILDREN'][0]['DATA']['Value'] = $priceDiscount;
        $updateFields = [
            'ACTIONS' => $arUpdateActions,
            'SORT' => $sort,
            'PRIORITY' => $priority,
            'ACTIVE_FROM' => $dateTimeStart,
            'ACTIVE_TO' => $dateTimeStop,
        ];
        return \CSaleDiscount::Update($ruleID, $updateFields);
    }

    /**
     * Удалить правило корзины
     *
     * @param int $id
     * @return bool
     */
    private function deleteRule(int $id): bool
    {
        if (!$id)
            return false;
        return (new \CSaleDiscount)->Delete($id);
    }

    /**
     * Получить данные о правиле корзины
     *
     * @param int $id
     * @return array|false
     */
    public static function getRule(int $id)
    {
        return \CSaleDiscount::GetByID($id);
    }

    /**
     * Удалить все правила корзины (от excel)
     *
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     * @throws LoaderException
     */
    public function deleteAllRule(): array
    {
        $dbResult = DiscountTable::getList(
            [
                'filter' => [
                    "LID" => $this->siteID,
                    "%NAME" => $this->rulePatternName[0]
                ],
                'select' => ['ID', 'NAME']
            ]
        );

        $arDeletedIds = [];
        while ($arResult = $dbResult->fetch()) {
            $arDeletedIds[] = $arResult['ID'];
            $result = $this->deleteRule((int)$arResult['ID']);

            if ($result) {
                $this->report[] = [
                    'type' => 'delete',
                    'text' => 'Скидка удалена. ID rule: ' . $arResult['ID'] . '; DiscountName: "' . $arResult['NAME'] . '"',
                    'sort' => 910,
                    'indexRow' => null
                ];
            } else {
                $this->report[] = [
                    'type' => 'error',
                    'text' => 'Ошибка. ID rule: ' . $arResult['ID'] . '; Скидка не удалена, DiscountName: "' . $arResult['NAME'] . '"',
                    'sort' => 190,
                    'indexRow' => null
                ];
            }
        }
        $this->clearCacheCatalog();

        return $arDeletedIds;
    }

    /**
     * Очистить кэш каталога после добавления правил
     *
     * @throws LoaderException
     */
    private function clearCacheCatalog(): void
    {
        \CIBlock::clearIblockTagCache($this->getIbProducts());
    }

    /**
     * Сохранение отчёта report в файл $this->files['logFile']
     */
    private function saveReport()
    {
        if (empty($this->report))
            return;

        $report = $this->report;
        $text = [];
        $excelData = !empty($this->importData) ? json_encode($this->importData, JSON_UNESCAPED_UNICODE) : null;
        array_multisort(array_column($report, 'sort'), SORT_ASC, array_column($report, 'indexRow'), SORT_ASC, $report);
        $stat = array_count_values(array_column($report, 'type'));

        $text[] = 'Отчёт ' . date('Y.m.d H:i:s');
        foreach ($stat as $key => $count) {
            $text[] = '[' . $key . ']: ' . $count;
        }
        $text[] = '';
        foreach ($report as $row) {
            $str = ($row['type']) ? '[' . $row['type'] . '] ' : '';
            $str .= ($row['indexRow']) ? 'Excel строка ' . $row['indexRow'] . '; ' : '';
            $str .= ($row['text']) ? $row['text'] . '; ' : ';';
            $str .= ($row['product']) ? ' Артикул "' . $row['product'] . '"' : '';

            $text[] = $str;
        }
        if (!empty($excelData))
            $text[] = 'Данные excel файла на ' . date('Y.m.d H:i:s') . ' ' . $excelData;
        $text = array_merge($text, array_fill(0, 4, ''));

        //echo implode(PHP_EOL, $text);
        file_put_contents($this->files['logFile'], implode(PHP_EOL, $text), FILE_APPEND);
    }
}