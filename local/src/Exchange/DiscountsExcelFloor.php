<?php
/**
 * @author Anton SH <phpinstall@gmail.com>
 */

namespace ASH\Exchange;

use Bitrix\Sale\Internals\DiscountTable;

/**
 * Класс для синхронизации правил корзины с excel файлом;
 * По варианту DiscountsExcelFloor
 *
 * Одно правило на МНОЖЕСТВО товаров,
 * значение скидки округляется до целого в МЕНЬШУЮ сторону (итоговая цена в большую),
 * размер скидки в процентах рассчитывается исходя из базовой цены на сайте.
 *
 * Основная идея: торговые предложения группируются по некоторому уникальному ключу, состоящему из целого значения скидки, даты начала и даты окончания активности;
 * Затем эти уникальные ключи записываются в свойство торгового предложения (каждому свой соответствующий);
 * Создаются правила работы с корзиной с привязкой к товарам, у которых установлены данные ключи (каждому правилу один соответствующий ключ группы товаров).
 */
class DiscountsExcelFloor extends DiscountsExcel
{
    /**
     * Шаблон названий правил работы корзин
     *
     * @var string[]
     */
    private array $rulePatternName;

    /**
     * Код свойства для значения скидки связанного с правилом корзины
     *
     * @var string
     */
    private string $propertyDiscountProduct;


    public function __construct()
    {
        parent::__construct();
        $this->rulePatternName = ['$AUTO_EXCEL_FLOOR', '$AUTO_EXCEL_FLOOR %s%% %s - %s'];
        $this->propertyDiscountProduct = 'LINK_DISCOUNT_RULE';
    }


    /**
     * Синхронизация правил корзины и офферов с excel файлом включает в себя:
     * - Получить целочисленный процент с округлением до целого нижнего
     * - Собрать таблицу уникальных [целая скидка, дата начала, дата окончания]
     * - Добавить к данным ещё переменные правил (сортировки и др)
     * - Обновить у офферов свойство ссылки на правило корзины (скидку) и очистить всем остальным
     * - Удалить старые неактуальные правила
     * - Добавить отсутствующие правила
     * - Добавление данных отчёт
     *
     * @return $this
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\LoaderException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     * @throws \Exception
     */
    protected function synchronizingRules(): DiscountsExcelFloor
    {
        // найти основные ID товаров по артикулу из excel
        $this->getIdsByParents();

        # собрать массив новых(всех) правил корзины из уникальных комбинаций скидки, даты начала и даты окончания
        # и обновить значение свойства оффера значением целочисленной скидки, связанное с правилом корзины, остальным очистить
        $ruleDbUniqueNow = [];
        $ruleDbNow = [];
        $offersIdDbActualRule = [];
        $dateTimeStart = new \DateTime('today');
        $dateTimeStop = \DateTime::createFromFormat('d/m/Y H:i:s', '31/12/2099 23:59:59');
        foreach ($this->importData as $itemRule) {
            //т.к. концепция завязана на времени активности, то это значение обязательно
            if ($itemRule['dateTimeStart'] === null) {
                $itemRule['dateTimeStart'] = $dateTimeStart;
            }
            if ($itemRule['dateTimeStop'] === null) {
                $itemRule['dateTimeStop'] = $dateTimeStop;
            }

            $offerId = $this->productsIdMap[$itemRule['article']];
            if (empty($offerId)) {
                $this->report[] = [
                    'type' => 'notFound',
                    'text' => 'Ошибка. Товар не найден',
                    'product' => $itemRule['article'],
                    'sort' => 110,
                    'indexRow' => $itemRule['indexRow']
                ];
                continue;
            }

            $newPrice = $itemRule['priceDiscount'];
            $discount = $this->getDiscountProduct($offerId, $newPrice, $basePrice);
            if ($discount > 0) {
                //сбор уникальных комбинаций правил корзины
                $uniqueString = sprintf($this->rulePatternName[1], $discount, $itemRule['dateTimeStart']->format('d.m.y H:i:s'), $itemRule['dateTimeStop']->format('d.m.y H:i:s'));;
                $ruleDbUniqueNow[$uniqueString] = [
                    'discount' => $discount,
                    'dateTimeStart' => $itemRule['dateTimeStart'],
                    'dateTimeStop' => $itemRule['dateTimeStop'],
                ];
                $ruleDbNow[$uniqueString][] = [
                    'offerId' => $offerId,
                    'article' => $itemRule['article'],
                ];

                //обновление актуальным значением скидки свойства оффера
                \CIBlockElement::SetPropertyValuesEx($offerId, false, array($this->propertyDiscountProduct => $uniqueString));
                $offersIdDbActualRule[] = $offerId;
            } else {
                $this->report[] = [
                    'type' => 'notice',
                    'text' => 'Скидка не применена: отрицательная или нулевая скидка: ' . $discount . '% Артикул "' . $itemRule['article'] . '"; Старая цена продукта: ' . $basePrice . ', новая цена ' . $newPrice,
                    'product' => $itemRule['article'],
                    'sort' => 200,
                    'indexRow' => $itemRule['indexRow']
                ];
            }
        }

        //очистка неактуальных значений свойства оффера "Ссылка скидки правила корзины"
        $iterator = \CIBlockElement::getList(
            [],
            [
                'IBLOCK_ID' => $this->getIbProducts(),
                '!ID' => $offersIdDbActualRule
            ],
            false,
            [],
            ['ID', 'IBLOCK_ID']
        );
        while ($row = $iterator->Fetch()) {
            \CIBlockElement::SetPropertyValuesEx($row['ID'], false, array($this->propertyDiscountProduct => ''));
        }

        //добавить переменные к правилам
        $nameRuleDbNew = [];
        array_multisort(array_column($ruleDbUniqueNow, 'discount'), SORT_ASC, $ruleDbUniqueNow);
        foreach ($ruleDbUniqueNow as &$itemRule) {
            $itemRule['nameRule'] = sprintf($this->rulePatternName[1], $itemRule['discount'], $itemRule['dateTimeStart']->format('d.m.y H:i:s'), $itemRule['dateTimeStop']->format('d.m.y H:i:s'));
            $nameRuleDbNew[] = $itemRule['nameRule'];
            $itemRule['sort'] = $this->getRuleSort($itemRule, null);
            $itemRule['priority'] = $this->getRulePriority($itemRule['discount']);
            $itemRule['dateTimeStart'] = $itemRule['dateTimeStart'] ? $itemRule['dateTimeStart']->format('d.m.Y H:i:s') : null;
            $itemRule['dateTimeStop'] = $itemRule['dateTimeStop'] ? $itemRule['dateTimeStop']->format('d.m.Y H:i:s') : null;
        }


        #Из старых правил выбираем те, которых нет в excel и удаляем
        //найти все существующие правила
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
        //удаление
        $ruleDiff = array_diff($dbIssetRules, $nameRuleDbNew);
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

        #Добавляем новые правила
        foreach ($ruleDbUniqueNow as $linkProducts => $addingRule) {
            $productReportList = '';
            foreach ($ruleDbNow[$linkProducts] as $product) {
                $productReportList .= 'offerId_' . $product['offerId'] . '=>article_' . $product['article'] . ', ';
            }
            if (!in_array($addingRule['nameRule'], $dbIssetRules)) {

                $resultId = $this->addRuleCommon(
                    $linkProducts,
                    $addingRule['discount'],
                    $addingRule['nameRule'],
                    $addingRule['priority'],
                    $addingRule['sort'],
                    'N',
                    'N',
                    [2],
                    $addingRule['dateTimeStart'],
                    $addingRule['dateTimeStop']
                );

                if ($resultId) {
                    $this->report[] = [
                        'type' => 'add',
                        'text' => 'Скидка добавлена. ID rule: ' . $resultId . ';  DiscountName: "' . $addingRule['nameRule'] . '"; Discount: ' . $addingRule['discount'] . '%; Для товаров: ' . $productReportList,
                        'sort' => 700,
                        'indexRow' => null
                    ];
                } else {
                    $this->report[] = [
                        'type' => 'error',
                        'text' => 'Ошибка. Скидка не добавлена; DiscountName: "' . $addingRule['nameRule'] . '"; Для товаров: ' . $productReportList,
                        'sort' => 120,
                        'indexRow' => null
                    ];
                }
            } else {
                $this->report[] = [
                    'type' => 'update',
                    'text' => 'Скидка существовала. ID rule: ' . array_search($addingRule['nameRule'], $dbIssetRules) . '; DiscountName: "' . $addingRule['nameRule'] . '"; Для товаров: ' . $productReportList,
                    'sort' => 800,
                    'indexRow' => null
                ];
            }
        }

        $this->report[] = [
            'type' => 'notice',
            'text' => 'Из ' . count($this->importData) . ' создано ' . count($ruleDbUniqueNow) . ' правил',
            'sort' => 10,
            'indexRow' => null
        ];

        return $this;
    }

    /**
     * Получить значение скидки
     * с округлением до целого в меньшую
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectNotFoundException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private function getDiscountProduct($productId, $newPrice, &$basePrice = 0)
    {
        static $basePrices = null;
        $basePrice = 0;
        if ($basePrices == null) {
            $basePrices = $this->getBasePrices(array_values($this->productsIdMap));
        }

        if (!empty($basePrices[$productId]) && $basePrices[$productId] > 0)
            $basePrice = (float)$basePrices[$productId];

        //($basePrice - $newPrice) / $basePrice * 100
        $discount = bcmul(bcdiv(bcsub($basePrice, $newPrice, 2), $basePrice, 4), 100, 2);

        return floor($discount);
    }

    /**
     * Индекс сортировки в уровне приоритета
     *
     * @param array $itemRule
     * @param int|null $productId
     * @return int
     */
    protected function getRuleSort(array $itemRule, ?int $productId): int
    {
        return 100;
    }

    /**
     * Приоритет применимости
     * В данном примере приоритет равняется целому проценту скидки, умноженному на 10
     *
     * @param float $discount
     * @return int
     */
    protected function getRulePriority(float $discount): int
    {
        return (int)$discount * 10;
    }

    /**
     * Возвращает идентификатор свойства в инфоблоке
     *
     * @param $iBlockID - ид инфоблока
     * @param $iBlockPropertyCode - код свойства
     * @return int|null
     * @throws \Exception
     */
    private function getIblockElementPropertyId($iBlockID, $iBlockPropertyCode): ?int
    {
        static $id = null;
        if ($id === null) {
            $ob = \CIBlockProperty::GetList([], [
                'IBLOCK_ID' => $iBlockID,
                'CODE' => $iBlockPropertyCode
            ])->Fetch();
            if (empty($ob['ID']) || !is_numeric($ob['ID'])) {
                throw new \Exception('Идентификатор свойства ' . $iBlockPropertyCode . ' не найден');
            }
            $id = (int)$ob['ID'];
        }
        return $id;
    }

    /**
     * Добавление правила корзины
     *
     * @param string $linkProducts - параметр, объединяющий товары
     * @param float $discount - целочисленная скидка
     * @param string $name - название правила корзины
     * @param int|null $priority - Приоритет применимости
     * @param int|null $sort - Индекс сортировки в уровне приоритета
     * @param string|null $lastLevelDiscount - Прекратить применение скидок на текущем уровне приоритетов
     * @param string|null $lastDiscount - Прекратить дальнейшее применение правил
     * @param array|int[] $userGroups - пользовательские группы; 2 - все
     * @param string|null $dateTimeStart - начало активности
     * @param string|null $dateTimeStop - окончание активности
     * @return false|int
     * @throws \Exception
     */
    private function addRuleCommon(
        string $linkProducts,
        float $discount,
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

        $linkProductsPropertyCode = 'CondIBProp:' . $this->getIbProducts() . ':' . $this->getIblockElementPropertyId($this->getIbProducts(), $this->propertyDiscountProduct);

        $addRuleData = [
            'LID' => $this->siteID,
            'SITE_ID' => $this->siteID,
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
                                        'Type' => 'Discount',
                                        'Value' => $discount,
                                        'Unit' => 'Perc',
                                        'Max' => 0,
                                        'All' => 'AND',
                                        'True' => 'True',
                                    ),
                                'CHILDREN' =>
                                    array(
                                        0 =>
                                            array(
                                                'CLASS_ID' => $linkProductsPropertyCode,
                                                'DATA' =>
                                                    array(
                                                        'logic' => 'Equal',
                                                        'value' => $linkProducts,
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

}