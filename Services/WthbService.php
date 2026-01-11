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

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Services;

use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerUtils as Utils;
use Schwendinger\Webtrees\Module\LinkEnhancer\Schema\SeedHelpTable;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\GedcomFilters\GedcomEncodingFilter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Illuminate\Database\Capsule\Manager as DB;
use Exception;
use Fisharebest\Webtrees\Exceptions\FileUploadException;



class WthbService { // stuff related to webtrees manual link handling
    protected string $help_table;
    protected string $std_url;
    protected string $wiki_url;

    public function __construct(string $helptable, string $std_url, string $wiki_url)
    {
        $this->help_table = $helptable;
        $this->std_url = $std_url;
        $this->wiki_url = $wiki_url;
    }

    /**
     * load csv data from stream into array needed to populate route_help_map table
     *
     * @param $stream
     * @param string $separator  character between data fields
     *
     * @return array<string>
     */
    private function loadCsvStream($stream, string $separator = ";", array &$result = []): array
    {
        $data = [];

        $header = fgetcsv($stream, null, $separator);
        $hdrcnt = count($header);
        
        $err_empty = [];
        $err_fieldcnt = [];

        $linenum = 2;
        $rowtotal = 0;

        while (($row = fgetcsv($stream, null, $separator)) !== false) {
            $rowcnt = count($row);
            if ($rowcnt === $hdrcnt) {
                $data[] = array_combine($header, $row);
            } elseif ($rowcnt == 1 && ($row[0] == null || trim($row[0]) == '')) {
                // empty line
                $err_empty[] = $linenum;
            } else {
                // fieldcnt mismatch
                $err_fieldcnt[] = $linenum;
            }
            $linenum++;
            $rowtotal++;
        }
        $result['total'] = $rowtotal;
        $result['skipped_empty'] = $err_empty;
        $result['skipped_fieldcnt'] = $err_fieldcnt;

        return $data;
    }

    /**
     * set flash message if an error occured while importing routes (wthb)
     */
    public function setImportFlashError(string $title, string $msg) {
        FlashMessages::addMessage(
            '<div class="import-flash"><strong>' . I18N::translate('Webtrees manual') . '</strong>: ' . $title . ' - ' . I18N::translate('Error occurred')
            . "<br/><code>$msg</code></div>",
            'danger'
        );
    }

    /**
     * set flash message for successfully or partially importing routes (wthb)
     */
    public function setImportFlashOk(string $title, array $result) {
        $skippedcnt = 0;

        $warnings = [
            'skipped_empty'        => /*I18N: csv import */I18N::translate('empty rows'),
            'skipped_fieldcnt'     => /*I18N: csv import */I18N::translate('rows whose number of fields differs from the header row'),
            'skipped_reqvalsempty' => /*I18N: data import with seeder */I18N::translate('rows in which at least one required field is not set') . ' (path, handler, method, extras)',
            'skipped_valuelength'  => /*I18N: data import with seeder */ I18N::translate('rows in which the maximum field value length has been exceeded'),
        ];
        $warnmsg = '';

        foreach ($warnings as $key => $value) {
            if (isset($result[$key])) {
                $cnt = count($result[$key]);
                if ($cnt > 0) {
                    $skippedcnt += $cnt;
                    $rownums = (is_array($result[$key]) ? '<br/><span class="skipped-rownums">' . implode(', ', $result[$key]) . '</span>': '');
                    $warnmsg .= "<li>$value: $cnt $rownums</li>";
                }
            }
        }

        $result['total'] = (!isset($result['total']) && isset($result['total_seeder']) ? $result['total_seeder'] : $result['total'] ?? 0);

        $message = '<strong>' . I18N::translate('Webtrees manual') . '</strong>: ' . $title . ' - ' . I18N::translate('Routes imported') 
            . '<dl><dt>' . /*I18N: webtrees.pot */I18N::translate('Total') . ':</dt><dd>' . $result['total'] . '</dd></dl>';
        $status = 'success';
        if ($skippedcnt > 0) {
            $message .= '<dl style="color:red;"><dt>' . /*I18N: wthb import skipped rows */I18N::translate('Skipped') . ':</dt><dd>' . $skippedcnt . '</dd></dl>';
            $message .= "<ul class=\"import-warn\">$warnmsg</ul>";
            $status = 'warning';
        }

        FlashMessages::addMessage('<div class="wthb-import-flash">' . $message . '</div>', $status);
    }

    /**
     * wrapper function with try-catch and flash message for import csv data from file or stream and feed it to the seeder
     */    
    public function importCsvFlash(string|StreamInterface $file, string $separator = ';', bool $truncate = true, string $encoding = '') {
        $title = I18N::translate('CSV-Import');
        try {
            $result = $this->importCsv($file, $separator, $truncate, $encoding);
        } catch (Exception $ex) {
            $this->setImportFlashError($title, $ex->getMessage());
            return;
        }

        $this->setImportFlashOk($title, $result);
    }

    /**
     * import csv data from file or stream and feed it to the seeder
     */
    public function importCsv(string|StreamInterface $file, string $separator = ';', bool $truncate = true, string $encoding = '')
    {
        $result = ['total' => 0, 'total_seeder' => 0, 'skipped_seeder' => 0];
        $data = [];
        if (gettype($file) == 'string') {
            if (file_exists($file)) {
                $stream = fopen($file, 'r');
                $data = $this->loadCsvStream($stream, $separator, $result);
                fclose($stream);
            } else {
                return $result;
            }
        } else {
            // similar to the implementation in:
            // - app/Services/TreeService.php
            // - resources/views/admin/trees-import.phtml
            $stream = $file->detach();
            stream_filter_append($stream, GedcomEncodingFilter::class, STREAM_FILTER_READ, ['src_encoding' => $encoding]);
            $data = $this->loadCsvStream($stream, $separator);
        }

        $seeder = new SeedHelpTable($data, $truncate);
        $seeder->run();
        $result['total_seeder']   = $seeder->cntRowsTotal;
        return array_merge($result, $seeder->skippedRows);
    }

    /**
     * Query route to help mapping table for total count of rows and count of mapped rows (where an url is set)
     *
     * @return array
     */
    public function getHelpTableCount(): array
    {
        $totalCnt = 0;
        $mappedCnt = 0;
        if (DB::schema()->hasTable($this->help_table)) {
            try {
                $totalCnt = DB::table($this->help_table)->count();
                $mappedCnt = DB::table($this->help_table)
                    ->whereNotNull('url')
                    ->where('url', '!=', '')
                    ->count();
            } catch (Exception $e) {
            }
        }

        return ['total' => (int) $totalCnt, 'assigned' => (int) $mappedCnt];
    }


    /**
     * import in webtrees registered routes into table route_help_map
     *
     * @return array  count of rows in total and skipped
     */
    public function importRoutesAction(ServerRequestInterface $request): array
    {
        $router = Registry::routeFactory()->routeMap();
        $existingRoutes = $router->getRoutes();
        $data = [];

        foreach ($existingRoutes as $route) {
            $extras = is_array($route->extras) && isset($route->extras['middleware']) ? implode('|', $route->extras['middleware']) : '';
            $data[] = [
                'path' => $route->path,
                'handler' => $route->name,
                'method' => implode('|', $route->allows),
                'extras' => $extras,
                'attr' => $route->attributes
            ];
        }

        $truncate = Validator::parsedBody($request)->boolean('table-truncate', false);

        $result = ['total' => 0];
        $seeder = new SeedHelpTable($data, $truncate);
        $seeder->run();
        $result['total_seeder']   = $seeder->cntRowsTotal;
        return array_merge($result, $seeder->skippedRows);
    }

    /**
     * Query route to help mapping table for matching entries
     *
     * @param array|null $activeroute
     * @param bool $withSubcontext     also query for subcontext topic rows
     *
     * @return mixed
     */
    public function getContextHelp(array|null $activeroute = null, bool $withSubcontext = true): mixed
    {
        $std_url = $this->std_url;
        $wiki_url = $this->wiki_url;
        if (!DB::schema()->hasTable($this->help_table)) {
            FlashMessages::addMessage(
                I18N::translate('Table for context help is missing - fallback to standard url'),
                'info'
            );
            return $std_url;
        }
        $url = $std_url;
        $activeroute ??= Utils::getActiveRoute();
        $sql = "";
        $result = null;
        $subcontext = [];


        if (is_array($activeroute) 
            && array_key_exists('path', $activeroute) 
            && array_key_exists('handler', $activeroute) 
            && array_key_exists('extras', $activeroute)
            && array_key_exists('attr', $activeroute)
        ) {
            // custom module?
            $module = str_starts_with($activeroute['path'], "/" . "module/") && ($activeroute['attr']['module'] ?? false)
                ? $activeroute['attr']['module']
                : '';

            // WHERE url is not null AND
            // (
            //      (path = route[path] AND handler = route[handler]) 
            //   OR (path = route[path] AND handler = '')
            //   OR (path = '' AND handler = route[handler])
            //   OR (path = route[path] AND handler = module)         #if route.path ^=/module/
            //   OR (handler = module)                                #if route.path ^=/module/
            //   OR (category=generic AND extras=route[extras])       #last try by Auth-Level
            // )
            $query = DB::table($this->help_table)
                ->whereNotNull('url')
                ->where('url', '!=', '')
                ->when(!$withSubcontext, function ($query1) {
                    $query1->where('subcontext', '=', '');
                })
                ->where(function ($query2) use ($module, $activeroute, $withSubcontext) {
                    $query2
                        ->where('path', '=', $activeroute['path'])
                        ->where('handler', '=', $activeroute['handler'])
                        ->orWhere('path', '=', $activeroute['path'])
                        ->where('handler', '=', '')
                        ->orWhere('path', '=', '')
                        ->where('handler', '=', $activeroute['handler'])
                        ->when($module != '', function ($query3) use ($module, $activeroute) {
                            $query3
                                ->orWhere('path', '=', $activeroute['path'])
                                ->where('handler', '=', $module)
                                ->orWhere('handler', '=', $module);
                        })
                        ->when(($activeroute['extras'] ?? false), function ($query4) use ($activeroute) {
                            $query4
                                ->orWhere('category', '=', 'generic')
                                ->where('extras', '=', $activeroute['extras']);
                        })
                        ->when($withSubcontext, function ($query5)  {
                            $query5
                                ->orWhere('category', '=', 'generic')
                                ->where('subcontext', '!=', '');
                        });
                })
                ->orderBy('order');
            
            $sql = $query->toRawSql();
            
            $result = $query
                ->get()
                ->map(function ($obj) use ($std_url, $wiki_url): mixed { // complete url by appending prefix to url path - also external url are possible
                    $first_url = trim($obj->url);
                    $obj->url = $first_url == '' ? $std_url : (preg_match('/^https?:\/\//', $first_url) ? $first_url : $wiki_url . ltrim($first_url, '/'));
                    return $obj;
                });

            if ($result->isNotEmpty()) {
                $firsturl = $result->firstWhere('subcontext', '=', "");
                $url = $firsturl ? $firsturl->url : $url;

                if ($withSubcontext) {
                    $subcontext = $result
                        ->filter(function($row) {
                            return $row->subcontext != '';
                        })
                        ->map(function($row){
                            return [
                                'ctx' => $row->subcontext,
                                'url' => $row->url,
                            ];
                        })
                        ->toArray();
                }
            }
        }

        return [
            'sql'        => $sql,
            'result'     => $result,
            'help_url'   => $url,
            'subcontext' => array_values($subcontext), // important for json_encode - ensure zero-based array numbering, otherwise it's encoded as object and not as array
        ];
    }

    public function exportCsvAction(string $filename, ServerRequestInterface $request): ResponseInterface
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . addcslashes($filename, '"') . '"',
        ];


        $separator = $this->getSeparator($request);
        if (!DB::schema()->hasTable($this->help_table)) {
            throw new Exception(I18N::translate('Table for context help is missing - nothing to do.'));
        }

        $data = DB::table($this->help_table)->get();

        if (count($data) == 0) {
            throw new Exception(I18N::translate('No data available for export.'));
        }

        ob_start();
        $file = fopen('php://output', 'w');
        $columns = array_keys(get_object_vars($data->first()));
        fputcsv($file, $columns, $separator, "\"", "\\", "\n");
        foreach ($data as $datarow) {
            $row = [];
            foreach ($columns as $column) {
                $row[] = $datarow->$column;
            }
            fputcsv($file, $row, $separator, "\"", "\\", "\n");
        }
        fclose($file);
        $csv = ob_get_clean();

        return response($csv, 200, $headers);
    }

    public function importCsvAction(ServerRequestInterface $request): void
    {
        $encodings = ['' => ''] + Registry::encodingFactory()->list();
        $encoding = Validator::parsedBody($request)->isInArrayKeys($encodings)->string('encoding');


        if (!DB::schema()->hasTable($this->help_table)) {
            throw new Exception(I18N::translate('Table for context help is missing - nothing to do.'));
        }

        $separator = $this->getSeparator($request);

        $truncate = Validator::parsedBody($request)->boolean('table-truncate', false);

        $csv_file = $request->getUploadedFiles()['csv-file'] ?? null;
        if ($csv_file === null || $csv_file->getError() === UPLOAD_ERR_NO_FILE) {
            throw new Exception(I18N::translate('No CSV file was received.'));
        }
        if ($csv_file->getError() !== UPLOAD_ERR_OK) {
            throw new FileUploadException($csv_file);
        }
        //app/Services/TreeService.php
        $stream = $csv_file->getStream();
        //$stream = $stream->detach();

        $result = $this->importCsvFlash($stream, $separator, $truncate, $encoding);
    }

    private function getSeparator(ServerRequestInterface $request): string
    {
        //$sepsallowed = [';', ',', '|', ':', '\\t'];
        $separator = trim(Validator::parsedBody($request)->string('csv-separator'));
        $separator = $separator == '\\t' ? "\t" : $separator;
        if (strlen($separator) === 0) {
            throw new Exception(I18N::translate('Separator is not set.'));
        }
        if (strlen($separator) > 1) {
            throw new Exception(I18N::translate('For the separator is only a single character allowed.'));
        }
        return $separator;
    }
}