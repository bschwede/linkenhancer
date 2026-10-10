<?php

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Services\AdminService;
use Fisharebest\Webtrees\Services\TimeoutService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Schwendinger\Webtrees\Helpers\MoreI18N;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\IndexRebuildScheduler;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\RenumberWithLinksService;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\UidIndexService;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefsService;

use function array_keys;
use function redirect;
use function route;

/**
 * Admin renumber flow: show cross-tree XREF collisions and execute renumbering.
 * Extracted from LinkEnhancerModule.
 */
final class RenumberActionHandler
{
    public function __construct(
        private readonly LinkEnhancerModule $module,
    ) {}

    /**
     * Returns the view name and data for the renumber page.
     * The module facade handles the viewResponse() call (protected in trait).
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function showPage(ServerRequestInterface $request): array
    {
        $params  = Validator::queryParams($request);
        $tree_id = (int) $params->integer('target_tree', 0);
        $trees   = Registry::container()->get(TreeService::class)->all();

        $tree           = null;
        $xrefs          = [];
        $inbound_counts = [];
        if ($tree_id > 0) {
            foreach ($trees as $t) {
                if ($t->id() === $tree_id) {
                    $tree = $t;
                    break;
                }
            }
            if ($tree !== null) {
                $xrefs = Registry::container()->get(AdminService::class)->duplicateXrefs($tree);
                foreach (array_keys($xrefs) as $xref) {
                    $inbound_counts[$xref] = DB::table(XrefsService::INDEX_LINK_TABLE)
                        ->where('target_xref', '=', $xref)
                        ->where(static function ($q) use ($tree): void {
                            $q->where(static function ($q2) use ($tree): void {
                                $q2->whereNull('target_tree')->where('file', '=', $tree->id());
                            })->orWhere('target_tree', '=', $tree->name());
                        })
                        ->count();
                }
            }
        }

        $renumber_result = Session::get('le-renumber-result', null);
        Session::forget('le-renumber-result');

        return [$this->module->name() . '::renumber-with-links', [
            'title'            => /*I18N: renumber xrefs */ I18N::translate('%s (with links)', MoreI18N::xlate('Renumber XREFs')),
            'module'           => $this->module,
            'tree'             => $tree,
            'trees'            => $trees,
            'xrefs'            => $xrefs,
            'inbound_counts'   => $inbound_counts,
            'renumber_result'  => $renumber_result,
            'link_status'      => XrefsService::indexStatus(),
            'uid_status'       => UidIndexService::indexStatus(),
            'cron_plan'        => IndexRebuildScheduler::deferPlan(),
        ]];
    }

    public function execute(ServerRequestInterface $request): ResponseInterface
    {
        $module = $this->module;
        $params  = Validator::parsedBody($request);
        $tree_id = (int) $params->integer('target_tree', 0);
        $trees   = Registry::container()->get(TreeService::class)->all();

        $tree = null;
        foreach ($trees as $t) {
            if ($t->id() === $tree_id) {
                $tree = $t;
                break;
            }
        }

        $redirect = route('module', ['module' => $module->name(), 'action' => 'AdminRenumber']
            + ($tree_id > 0 ? ['target_tree' => $tree_id] : []));

        if ($tree === null) {
            FlashMessages::addMessage(MoreI18N::xlate('No valid tree selected.'), 'danger');
            return redirect($redirect);
        }

        if ($tree->hasPendingEdit()) {
            FlashMessages::addMessage(
                MoreI18N::xlate('You need to accept or reject all pending changes before renumbering.'),
                'danger'
            );
            return redirect($redirect);
        }

        $service = new RenumberWithLinksService(
            Registry::container()->get(AdminService::class),
            Registry::container()->get(TimeoutService::class),
            Registry::container()->get(TreeService::class),
        );
        $report = $service->renumber($tree);

        Session::put('le-renumber-result', [
            'per'         => $report['per'] ?? [],
            'timed_out'   => (bool) ($report['timed_out'] ?? false),
            'defer_index' => (bool) ($report['defer_index'] ?? false),
        ]);

        $type = !empty($report['timed_out']) ? 'warning' : 'success';
        FlashMessages::addMessage($this->summary($report), $type);

        return redirect($redirect);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function summary(array $report): string
    {
        if (!empty($report['timed_out'])) {
            return MoreI18N::xlate('The time limit was reached; no changes were made.');
        }

        $message = MoreI18N::xlate(
            'Renumbered %1$d conflicting record(s) and updated %2$d le-link reference(s).',
            (int) $report['count'],
            (int) $report['links']
        );

        $message .= $report['defer_index']
            ? ' ' . MoreI18N::xlate('The le_* index will be refreshed by the cron job.')
            : ' ' . MoreI18N::xlate('The le_* index was updated inline.');

        return $message;
    }
}
