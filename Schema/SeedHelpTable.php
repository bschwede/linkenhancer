<?php
declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Schema;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\SeedInterface;

/**
 * Populate the default_resn table
 */
class SeedHelpTable //implements SeedInterface
{
    private const array HEADER = ['path', 'handler', 'method', 'extras', 'category', 'order', 'url'];

    private array $data = [];
    private bool $truncate;

    public int $cntRowsTotal;
    public int $cntRowsSkipped;
    public int $cntRowsProcessed;
    

    public function __construct(array $data, bool $truncate = true) {
        $this->data = $data;
        $this->truncate = $truncate;
        $this->cntRowsTotal     = count($this->data);
        $this->cntRowsSkipped   = 0;
        $this->cntRowsProcessed = 0;
    }


    public function skipRow(array $row): bool
    {
        return (bool) ($row['path'] ?? '' . $row['handler'] ?? '' . $row['method'] ?? '' . $row['extras'] ?? '') == '';
    }


    public function setRowDefaults(array &$row): void {
        $row['path'] ??= '';
        $row['handler'] ??= '';
        $row['method'] ??= '';
        $row['extras'] ??= '';
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
            foreach ($this->data as $row) {
                $this->setRowDefaults($row);

                if (!$this->skipRow($row)) {
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
                    ], $updateValues);
                    $this->cntRowsProcessed++;
                } else {
                    $this->cntRowsSkipped++;
                }
            }
        }
    
    }    
}