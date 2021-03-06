# Создание скидок из прайс-листа в 1С-Битрикс

___

### Описание:

Решение предназначено для интернет-магазина 1С-Битрикс.  

#### Вариант "DiscountsExcel"  
Через excel-файл с прайс-листами устанавливаются скидки на торговые предложения с датой активности и итоговой **фиксированной ценой**.  
На каждое торговое предложение будет создано правило корзины.  
Плюс: точная итоговая цена;  
Минус: при больших объёмах импорта **значительно** влияет на скорость загрузки сайта.  

#### Вариант "DiscountsExcelFloor"  
Через excel-файл с прайс-листами устанавливаются скидки на торговые предложения с датой активности. **Значение скидки округляется до целого в меньшую сторону**.
**Итоговая цена, в зависимости от дробного процента скидки, может быть увеличена до 1%**.  
Основная идея: торговые предложения группируются по некоторому уникальному ключу, состоящему из целого значения скидки, даты начала и даты окончания активности; затем эти уникальные ключи записываются в свойство торгового предложения (каждому свой соответствующий); создаются правила работы с корзиной с привязкой к товарам, у которых установлены данные ключи в свойствах (каждому правилу один соответствующий ключ группы товаров).  
Плюс: при больших объёмах импорта не влияет на скорость загрузки сайта;  
Минус: если значение итоговой стоимости является дробной скидкой, то итоговая цена соответствует прайсу с погрешностью 1% (т.е. итоговая сумма может быть на 0-1% больше).  
  
### Установка:
Выбрать предпочитаемый вариант в файле local/tasks/discountsExcel.php  
- (new ASH\Exchange\DiscountsExcel())->execute();
- (new ASH\Exchange\DiscountsExcelFloor())->execute();  

Для варианта "DiscountsExcelFloor" необходимо добавить новое строковое свойство "Ссылка скидки правила корзины" в инфоблок "Торговые предложения" с кодом "LINK_DISCOUNT_RULE".   

### Использование:

- установить скидочные прайс-листы в файле upload/exchange/import/autoImportRuleBasket.xlsx
- из браузера/консоли/cron запустить local/tasks/discountsExcel.php (повторная синхронизация запустится только после изменения файла)
- отчёт о синхронизации сгенерируется в файле upload/exchange/import/autoImportRuleBasket.txt с разбиением на категории [notFound, notice, validation, add, update, delete]

#### Требования:

```
composer require phpoffice/phpspreadsheet
bcmath
```

#### Протестировано:

- Marketplace шаблон "Современный интернет-магазин" (bitrix.eshop) v 21.0.200
- phpoffice/phpspreadsheet v 1.21
- PHP 7.4

###### Дополнительно

Прайсы с датой окончания активности < time() - игнорируются.  
Среди прайсов-дубликатов по артикулу выбираются позиции с меньшей ценой, иные игнорируются.  
Приоритет применимости равен целому проценту скидки, умноженному на 10.  
Индекс сортировки в уровне приоритета равен целому значению прайса.  
Ранее созданные данным методом правила корзины, которые отсутствуют в актуальном файле будут удалены, а существующие - обновлены.  