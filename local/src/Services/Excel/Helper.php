<?php

namespace ASH\Services\Excel;

/*
 * Класс для работы с Excel файлами
 * composer require phpoffice/PhpSpreadsheet
 *
 * https://github.com/PHPOffice/PhpSpreadsheet
 * https://phpspreadsheet.readthedocs.io/en/latest/
 * https://phpspreadsheet.readthedocs.io/en/latest/topics/reading-files/
 * https://phpspreadsheet.readthedocs.io/en/latest/topics/defined-names/#named-range-scope
 */

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception;

class Helper
{

    /**
     * Прочитать Excel-файл;
     * Возвращает многомерный массив данных из Excel файла
     * @param array $params [
     *  file - ссылка на файл, обязательный
     *  columns - диапазон столбцов
     *  startRow - первая строка
     *  endRow - последняя строка
     *  sheetName - array || string - название листов, необязательный, если указать - вернётся только указанный
     * ]
     *
     * @return array|mixed
     * @throws Exception
     */
    public static function read(array $params)
    {
        $arData = [];
        $inputFileName = $params['file'];

        /**  Identify the type of $inputFileName  **/
        $inputFileType = IOFactory::identify($inputFileName);
        /**  Create a new Reader of the type defined in $inputFileType  **/
        $reader = IOFactory::createReader($inputFileType);

        /**  Advise the Reader that we only want to load cell data  **/
        //$reader->setReadDataOnly(true);

        /**  Advise the Reader of which WorkSheets we want to load  **/
        if (!empty($params['sheetName']))
            $reader->setLoadSheetsOnly($params['sheetName']);
        /**  Tell the Reader that we want to use the Read Filter  **/
        $filterSubset = new ReadFilter($params['startRow'] ?? null, $params['endRow'] ?? null, $params['columns'] ?? null);
        $reader->setReadFilter($filterSubset);

        /**  Load only the rows and columns that match our filter to Spreadsheet  **/
        $spreadsheet = $reader->load($inputFileName);

        /** \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::toArray
         * Create array from worksheet.
         * @param mixed $nullValue Value returned in the array entry if a cell doesn't exist
         * @param bool $calculateFormulas Should formulas be calculated?
         * @param bool $formatData Should formatting be applied to cell values?
         * @param bool $returnCellRef :
         *  False - Return a simple array of rows and columns indexed by number counting from zero
         *  True - Return rows and columns indexed by their actual row and column IDs
         */
        foreach ($spreadsheet->getAllSheets() as $worksheet) {
            $arData[$worksheet->getTitle()] = $worksheet->toArray(null, true, true, true);
        }

        if (is_string($params['sheetName']))
            return !empty($params['sheetName']) ? $arData[$params['sheetName']] : $arData;
        return $arData;

    }
}


