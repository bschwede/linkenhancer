<?php

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers;

use Exception;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Schwendinger\Webtrees\Helpers\MoreI18N;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\WthbService;

use function file_exists;
use function hash_file;
use function redirect;

/**
 * WTHB (Webtrees Handbuch) admin actions: CSV import/export, route import, reset.
 * Extracted from LinkEnhancerModule — all delegate to WthbService with
 * try/catch + Flash message boilerplate.
 */
final class WthbAdminHandler
{
    public function __construct(
        private readonly WthbService $wthb,
        private readonly LinkEnhancerModule $module,
    ) {}

    public function resetRoutes(ServerRequestInterface $request): ResponseInterface
    {
        $this->importDelivered();
        $csvfile = LinkEnhancerModule::HELP_CSV;
        if (file_exists($csvfile)) {
            $this_hash = hash_file('sha256', $csvfile);
            if ($this_hash) {
                $this->module->setPref(LinkEnhancerModule::PREF_WTHB_LASTHASH, $this_hash);
            }
        }
        return redirect($this->module->getConfigLink());
    }

    public function importRoutes(ServerRequestInterface $request): ResponseInterface
    {
        $title = I18N::translate('Import registered routes');
        try {
            $result = $this->wthb->importRoutesAction($request);
            $this->wthb->setImportFlashOk($title, $result);
        } catch (Exception $ex) {
            $this->wthb->setImportFlashError($title, $ex->getMessage());
        }

        return redirect($this->module->getConfigLink());
    }

    public function cmmConfig2Csv(ServerRequestInterface $request): ResponseInterface
    {
        $filename = 'wthb-route-mapping-export-cmm.csv';
        try {
            return $this->wthb->exportCmmCsvAction($filename, $request);
        } catch (Exception $ex) {
            FlashMessages::addMessage(
                MoreI18N::xlate('Export failed') . ' - Custom Module Manager config<hr><samp dir="ltr">' . $ex->getMessage() . '</samp>',
                'danger'
            );
            return redirect($this->module->getConfigLink());
        }
    }

    public function csvExport(ServerRequestInterface $request): ResponseInterface
    {
        $filename = 'wthb-route-mapping-export.csv';
        try {
            return $this->wthb->exportCsvAction($filename, $request);
        } catch (Exception $ex) {
            FlashMessages::addMessage(
                MoreI18N::xlate('Export failed') . '<hr><samp dir="ltr">' . $ex->getMessage() . '</samp>',
                'danger'
            );
            return redirect($this->module->getConfigLink());
        }
    }

    public function csvImport(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->wthb->importCsvAction($request);
        } catch (Exception $ex) {
            FlashMessages::addMessage(
                MoreI18N::xlate('Import failed') . '<hr><samp dir="ltr">' . $ex->getMessage() . '</samp>',
                'danger'
            );
        }
        return redirect($this->module->getConfigLink());
    }

    public function importDelivered(): void
    {
        $this->wthb->importCsvFlash(LinkEnhancerModule::HELP_CSV);
    }
}
