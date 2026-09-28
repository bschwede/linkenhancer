<?php

/*
 * webtrees - linkenhancer (custom module)
 *
 * Copyright (C) 2026 Bernd Schwendinger
 *
 * webtrees: online genealogy application
 * Copyright (C) 2026 webtrees development team.
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

use Throwable;

use function class_exists;

/**
 * Defer the le_* index rebuild to the cronjob module (event-driven) instead of
 * repairing the index inline in a web request.
 *
 * This is the ONLY place in linkenhancer that knows about the optional cronjob
 * module: the classes are referenced by string and guarded with class_exists()
 * + try/catch, so linkenhancer has no hard dependency on cronjob. When cronjob is
 * absent, not enabled, or no active job listens for an event, callers fall back
 * to the inline index repair.
 *
 * It reuses the module's existing index jobs and events (see cron-jobs.php):
 * the link index (le_link_index + le_record_scan) and the UID index
 * (le_uid_index) each have their own job + dirty event, so each can be deferred
 * independently of the other.
 */
final class IndexRebuildScheduler
{
    /** Link index dirty event (drained by the "link-index" job / build-link-index.php). */
    public const EVENT_LINK_INDEX = 'linkenhancer:index-dirty';

    /** UID index dirty event (drained by the "uid-index" job / build-uid-index.php). */
    public const EVENT_UID_INDEX = 'linkenhancer:uid-index-dirty';

    /**
     * Which of the two index rebuilds can be deferred to cron, independently.
     *
     * @return array{link: bool, uid: bool}
     */
    public static function deferPlan(): array
    {
        return [
            'link' => self::canDefer(self::EVENT_LINK_INDEX),
            'uid'  => self::canDefer(self::EVENT_UID_INDEX),
        ];
    }

    /**
     * Is cronjob active AND is there an enabled job listening for $event? No
     * side effect.
     */
    public static function canDefer(string $event): bool
    {
        $svc = '\Schwendinger\Webtrees\Module\Cronjob\Services\CronjobService';
        if (!class_exists($svc)) {
            return false;
        }

        try {
            return $svc::isEnabled() && $svc::listenersFor($event) !== [];
        } catch (Throwable) {
            return false; // cronjob not migrated / tables missing
        }
    }

    /**
     * Push the dirty event(s) for the deferred index parts (call after the change
     * is committed).
     *
     * @param array{link: bool, uid: bool} $plan
     */
    public static function defer(array $plan, ?int $tree_id): void
    {
        $payload = $tree_id !== null ? ['tree' => $tree_id] : [];
        if (!empty($plan['link'])) {
            self::push(self::EVENT_LINK_INDEX, $payload);
        }
        if (!empty($plan['uid'])) {
            self::push(self::EVENT_UID_INDEX, $payload);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function push(string $event, array $payload): void
    {
        $svc = '\Schwendinger\Webtrees\Module\Cronjob\Services\CronjobService';
        if (!class_exists($svc)) {
            return;
        }

        try {
            if (!$svc::isMigrated() || !$svc::isEnabled()) {
                return;
            }
            $svc::pushEvent($event, $payload);
        } catch (Throwable) {
            // never break the caller because of the optional deferral
        }
    }
}
