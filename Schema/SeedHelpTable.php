<?php
/*
 * webtrees - linkenhancer (custom module)
 *
 * Copyright (C) 2025 Bernd Schwendinger
 *
 * webtrees: online genealogy application
 * Copyright (C) 2025 webtrees development team.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; If not, see <https://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\SeedInterface;

/**
 * Populate the route_hel_map table
 */
class SeedHelpTable implements SeedInterface
{
    private const array HEADER = [
    //  fieldname    => max length of string; getting info from schema is not directly supported
        'path'       => 100,
        'handler'    => 150,
        'method'     => 20,
        'extras'     => 60,
        'subcontext' => 250,
        'category'   => 60,
        'order'      => null, //integer
        'url'        => 250
    ];

    private array $data = [];
    private bool $truncate;

    public int $cntRowsTotal;
    public int $cntRowsProcessed;
    public array $skippedRows;
    

    public function __construct(array $data, bool $truncate = true) {
        $this->data = $data;
        $this->truncate = $truncate;
        $this->cntRowsTotal     = count($this->data);
        $this->cntRowsProcessed = 0;
        $this->skippedRows      = [];
    }


    public function skipRow(array $row, string &$skipreason = ''): bool
    {
        if ((bool) (($row['path'] ?? '') . ($row['handler'] ?? '') . ($row['method'] ?? '') . ($row['extras'] ?? '')) == '') {
            $skipreason = 'reqvalsempty';
            return true;
        }
        foreach (self::HEADER as $key => $maxlen) {
            if (is_int($maxlen) && isset($row[$key]) && is_string($row[$key]) && strlen($row[$key]) > $maxlen) {
                $skipreason = 'valuelength';
                return true;
            }
        }
        return false;
    }


    public function setRowDefaults(array &$row): void {
        $row['path'] ??= '';
        $row['handler'] ??= '';
        $row['method'] ??= '';
        $row['extras'] ??= '';
        $row['subcontext'] ??= '';
        $row['url'] ??= '';
       
        $handler = $row['handler'] ?? null;
        $category = $row['category'] ?? '';

        if ($handler && !$category) {
            if (str_starts_with($handler, 'Fisharebest\Webtrees\Http\RequestHandlers\\')) {
                $category = str_contains($handler, 'Redirect') ? 'standard redirect' : 'standard';
            } elseif (str_starts_with($handler, 'Fisharebest\Webtrees\Module\\')) {
                $category = 'standard module';
            } elseif (count(explode('\\', $handler)) > 2) {
                $category = 'custom module';
            }
        }

        $row['category'] = $category ?? ($row['path'] ?? '' == '' || $row['handler'] ?? '' == '' || $row['method'] ?? '' == '' ? 'generic' : 'standard');
        
        if (!isset($row['order']) || !is_int($row['order'])) {
            $row['order'] ??= $row['category'] == 'generic' ? 20 : 10;
        }
    }

    /**
     *  Run the seeder.
     *
     * @return void
     */
    public function run(): void
    {
        $table = 'route_help_map';
        if (!DB::schema()->hasTable($table)) return;
        $now = date('Y-m-d H:i:s');


        if ($this->truncate) {
            //DB::table('route_help_map')->truncate(); // => error trace with PHP Version 8.3.20 and mysql
            // There is no active transaction …/vendor/illuminate/database/Concerns/ManagesTransactions.php:51
            // #0 …/vendor/illuminate/database/Concerns/ManagesTransactions.php(51): PDO->commit()
            // #1 …/app/Http/Middleware/UseTransaction.php(44): Illuminate\Database\Connection->transaction()
            // #2 …/vendor/oscarotero/middleland/src/Dispatcher.php(136): Fisharebest\Webtrees\Http\Middleware\UseTransaction->process()            
            DB::table($table)->delete();
        }


        if ($this->cntRowsTotal != 0) {
            $rownum = 1;
            foreach ($this->data as $row) {
                $this->setRowDefaults($row);
                $skipreason = '';

                if (!$this->skipRow($row, $skipreason)) {
                    $updateValues = [];
                    foreach (['category', 'order', 'url'] as $attr) {
                        if ($row[$attr]) {
                            $updateValues[$attr] = $row[$attr];
                        }
                    }
                    $updateValues['updated_at'] = $now;
                      
                    DB::table($table)->updateOrInsert([
                        'path'       => $row['path'],
                        'handler'    => $row['handler'],
                        'method'     => $row['method'],
                        'extras'     => $row['extras'],
                        'subcontext' => $row['subcontext'],
                    ], $updateValues);
                    $this->cntRowsProcessed++;
                } else {
                    $key = 'skipped_' . $skipreason;
                    if (!isset($this->skippedRows[$key])) {
                        $this->skippedRows[$key] = [];
                    }
                    $this->skippedRows[$key][] = $rownum;
                }
                $rownum++;
            }
        }
    
    }    
}