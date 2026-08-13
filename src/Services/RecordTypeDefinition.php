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

/**
 * Definition eines Record-Typs mit seinen DB-Metadaten.
 */
final class RecordTypeDefinition {
    public function __construct(
        public readonly string $key,       // Interne Kennung (Darstellung/Fehlermeldungen)
        public readonly string $table,     // DB-Tabelle
        public readonly ?string $prefix,   // PK-Präfix (i,f,o,s,m) — null für HTML
        public readonly ?string $oType,    // o_type-Wert — null für Nicht-other-Tabellen
        public readonly string $label,     // Anzeigename im View
    ) {}
}
