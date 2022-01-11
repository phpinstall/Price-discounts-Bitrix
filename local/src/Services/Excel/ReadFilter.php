<?php

namespace ASH\Services\Excel;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**  Define a Read Filter class implementing \PhpOffice\PhpSpreadsheet\Reader\IReadFilter  */
class ReadFilter implements IReadFilter
{
    private $startRow;
    private $endRow;
    private $columns;

    /**  Get the list of rows and columns to read  */
    public function __construct($startRow = null, $endRow = null, $columns = null)
    {
        $this->startRow = $startRow ?: 1;
        $this->endRow = $endRow ?: 9999999;
        $this->columns = $columns ?: range('A', 'Z');
    }

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        //  Only read the rows and columns that were configured
        if ($row >= $this->startRow && $row <= $this->endRow) {
            if (in_array($columnAddress, $this->columns)) {
                return true;
            }
        }
        return false;
    }
}