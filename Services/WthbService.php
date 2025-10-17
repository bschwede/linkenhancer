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
    private function loadCsvStream($stream, string $separator = ";"): array
    {
        $data = [];

        $header = fgetcsv($stream, null, $separator);

        while (($row = fgetcsv($stream, null, $separator)) !== false) {
            $data[] = array_combine($header, $row);
        }

        return $data;
    }


    /**
     * import csv data from file or stream and feed it to the seeder
     */
    public function importCsv(string|StreamInterface $file, string $separator = ';', bool $truncate = true, string $encoding = '')
    {
        $result = ['total' => 0, 'skipped' => 0];
        $data = [];
        if (gettype($file) == 'string') {
            if (file_exists($file)) {
                $stream = fopen($file, 'r');
                $data = $this->loadCsvStream($stream, $separator);
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
        $result = ['total' => $seeder->cntRowsTotal, 'skipped' => $seeder->cntRowsSkipped];

        return $result;
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
     * returns informations for active route of current request; needed for context help
     *
     * @param ServerRequestInterface|null $request
     *
     * @return array
     */
    public function getActiveRoute(ServerRequestInterface|null $request = null): array
    {
        $request ??= Registry::container()->get(ServerRequestInterface::class);

        $route = $request->getAttribute('route');
        if ($route) {
            $extras = is_array($route->extras) && isset($route->extras['middleware']) ? implode('|', $route->extras['middleware']) : '';
            return [
                'path' => $route->path,
                'handler' => $route->name,
                'method' => implode('|', $route->allows),
                'extras' => $extras,
                'attr' => $route->attributes
            ];

        }
        return [];
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

        $result = ['total' => 0, 'skipped' => 0];
        $seeder = new SeedHelpTable($data, $truncate);
        $seeder->run();
        $result = ['total' => $seeder->cntRowsTotal, 'skipped' => $seeder->cntRowsSkipped];
        return $result;
    }

    /**
     * Query route to help mapping table for matching entries
     *
     * @param array|null $activeroute
     *
     * @return mixed
     */
    public function getContextHelp(array|null $activeroute = null): mixed
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
        $activeroute ??= $this->getActiveRoute();

        if ($activeroute) {
            // custom module?
            $module = str_starts_with($activeroute['path'], "/" . "module/") && ($activeroute['attr']['module'] ?? false)
                ? $activeroute['attr']['module']
                : '';

            // WHERE url is not null AND
            // (
            //      (path = route[path] AND handler = route[handler]) 
            //   OR (path = route[path] AND handler = module)         #if route.path ^=/module/
            //   OR (handler = module)                                #if route.path ^=/module/
            //   OR (category=generic AND extras=route[extras])       #last try by Auth-Level
            // )
            $result = DB::table($this->help_table)
                ->whereNotNull('url')
                ->where('url', '!=', '')
                ->where(function ($query) use ($module, $activeroute) {
                    $query
                        ->where('path', '=', $activeroute['path'])
                        ->where('handler', '=', $activeroute['handler'])
                        ->when($module != '', function ($query2) use ($module, $activeroute) {
                            $query2
                                ->orWhere('path', '=', $activeroute['path'])
                                ->where('handler', '=', $module)
                                ->orWhere('handler', '=', $module);
                        })
                        ->when(($activeroute['extras'] ?? false), function ($query3) use ($activeroute) {
                            $query3
                                ->orWhere('category', '=', 'generic')
                                ->where('extras', '=', $activeroute['extras']);
                        });
                })
                ->orderBy('order')
                ->get()
                ->map(function ($obj) use ($std_url, $wiki_url): mixed { // complete url by appending prefix to url path - also external url are possible
                    $first_url = trim($obj->url);
                    $obj->url = $first_url == '' ? $std_url : (preg_match('/^https?:\/\//', $first_url) ? $first_url : $wiki_url . ltrim($first_url, '/'));
                    return $obj;
                });

            if (!$result->isEmpty()) {
                return $result;
            }
        }

        return $url;
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

        $result = $this->importCsv($stream, $separator, $truncate, $encoding);
        FlashMessages::addMessage(
            I18N::translate('Routes imported (Total: %s / skipped: %s)', $result['total'], $result['skipped']),
            'success'
        );
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