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

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Location;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\Note;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Repository;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Source;
use Fisharebest\Webtrees\Tree;

use function strlen;
use function ltrim;

class XrefOverviewService {

	private array $recordTypes;
	private array $trees;

	public function __construct() {
		$this->recordTypes = $this->buildRecordTypes();
		$treeService = Registry::container()->get(TreeService::class);
		$this->trees = $treeService->all()->toArray();
	}

	public function getRecordTypes(): array {
		return $this->recordTypes;
	}

	public function getTrees(): array {
		return $this->trees;
	}

	/**
	 * @param array{
	 *     xref_filter?: string,
	 *     source_xref_filter?: string,
	 *     broken_only?: string
	 * } $params
	 */
	public function searchXrefs(array $params): array {
		$results = [];
		$xrefFilter = $this->buildXrefFilter($params['xref_filter'] ?? '');
		$sourceXrefFilter = $params['source_xref_filter'] ?? '';
		$brokenOnly = ($params['broken_only'] ?? '0') === '1';

		foreach ($this->recordTypes as $def) {
			$rows = $this->fetchRecordsForType($def, $xrefFilter, $sourceXrefFilter);
			foreach ($rows as $row) {
				$xrefs = $this->extractXrefsFromGedcom($row->gedcom, $def);
				foreach ($xrefs as $xrefEntry) {
					if ($xrefEntry['xref'] === '') {
						continue;
					}
					$validation = $this->validateXref(
						$xrefEntry['xref'],
						$xrefEntry['target_tree'] ?? null,
						$xrefEntry['rectype'] ?? null
					);
					if ($brokenOnly && $validation['is_valid'] && !$validation['has_type_mismatch']) {
						continue;
					}
					$results[] = [
						'table'             => $def->key,
						'xref'              => $def->label,
						'table_prefix'      => $row->table_prefix,
						'record_xref'       => $row->record_xref,
						'tree_id'           => $row->tree_id,
						'tree_name'         => $row->tree_name,
						'found_in'          => $row->found_in,
						'xref_target'       => $xrefEntry['xref'],
						'xref_type'         => $xrefEntry['type'],
						'target_tree_id'    => $xrefEntry['target_tree'] ?? null,
						'target_tree_name'  => $xrefEntry['target_tree_name'] ?? null,
						'is_valid'          => $validation['is_valid'],
						'has_type_mismatch' => $validation['has_type_mismatch'],
						'type_declared'     => $validation['type_declared'] ?? null,
						'type_actual'       => $validation['type_actual'] ?? null,
						'link'              => $row->link,
					];
				}
			}
		}

		usort($results, function ($a, $b) {
			$c = strcmp($a['tree_name'], $b['tree_name']);
			if ($c !== 0) return $c;
			$c = strcmp($a['xref'], $b['xref']);
			if ($c !== 0) return $c;
			$c = strcmp($a['record_xref'], $b['record_xref']);
			if ($c !== 0) return $c;
			return strcmp($a['xref_target'], $b['xref_target']);
		});

		return $results;
	}

	public function validateXref(string $xref, ?string $targetTree = null, ?string $declaredType = null): array {
		$resolvedTree = null;

		if ($targetTree !== null && $targetTree !== '') {
			foreach ($this->trees as $tree) {
				if ($tree->name() === $targetTree) {
					$resolvedTree = $tree;
					break;
				}
			}
		}

		// Determine existence
		$exists = false;
		if ($resolvedTree !== null) {
			$exists = $this->recordExistsInTree($xref, $resolvedTree);
		} else {
			foreach ($this->trees as $tree) {
				if ($this->recordExistsInTree($xref, $tree)) {
					$exists = true;
					break;
				}
			}
		}

		// Type validation for enhanced links — only if tree is resolved
		$typeCheck = ['valid' => true];
		if ($resolvedTree !== null) {
			$typeCheck = $this->validateRecordType($declaredType, $xref, $resolvedTree);
		}

		$hasTypeMismatch = !$typeCheck['valid'] && $declaredType !== null;

		return [
			'is_valid'          => $exists,
			'has_type_mismatch' => $hasTypeMismatch,
			'type_declared'     => $typeCheck['declared'] ?? null,
			'type_actual'       => $typeCheck['actual'] ?? null,
		];
	}

	public function countXrefs(array $params): int {
		return count($this->searchXrefs($params));
	}

	/**
	 * @return array<RecordTypeDefinition>
	 */
	private function buildRecordTypes(): array {
		return [
			new RecordTypeDefinition('individual',  'individuals', 'i', null, 'Individual'),
			new RecordTypeDefinition('family',      'families',    'f', null, 'Family'),
			new RecordTypeDefinition('note',        'other',       'o', 'NOTE', 'Note'),
			new RecordTypeDefinition('shared_note', 'other',       'o', 'NOTE', 'Shared Note'),
			new RecordTypeDefinition('source',      'sources',     's', null, 'Source'),
			new RecordTypeDefinition('media',       'media',       'm', null, 'Media'),
			new RecordTypeDefinition('repository',  'other',       'o', 'REPO', 'Repository'),
			new RecordTypeDefinition('location',    'other',       'o', '_LOC', 'Location'),
		];
	}

	/**
	 * @param RecordTypeDefinition $def
	 * @param array<string,int|null> $xrefFilter
	 * @param string $sourceXrefFilter
	 * @return array<object>
	 */
	private function fetchRecordsForType(RecordTypeDefinition $def, array $xrefFilter, string $sourceXrefFilter): array {
		$results = [];
		$table = $def->table;

		foreach ($this->trees as $tree) {
			if ($table === 'other') {
				$sub = $this->fetchOtherRecords($def, $tree, $xrefFilter, $sourceXrefFilter, $def->oType);
				$results = array_merge($results, $sub);
			} elseif ($table === 'html') {
				$sub = $this->fetchHtmlBlocks($tree, $xrefFilter, $sourceXrefFilter);
				$results = array_merge($results, $sub);
			} else {
				$sub = $this->fetchStandardTableRecords($table, $def->prefix, $tree, $xrefFilter, $sourceXrefFilter);
				$results = array_merge($results, $sub);
			}
		}

		return $results;
	}

	private function fetchOtherRecords(RecordTypeDefinition $def, Tree $tree, array $xrefFilter, string $sourceXrefFilter, ?string $oType): array {
		$results = [];

		$query = DB::table('other')
			->select('o_id as record_xref', 'o_gedcom as gedcom', 'o_file as tree_id')
			->where('o_file', '=', $tree->id())
			->where('o_type', '=', $oType);

		// Apply source xref filter on the record itself
		if ($xrefFilter !== []) {
			$query->where(function ($q) use ($xrefFilter) {
				foreach ($xrefFilter as $val) {
					$q->orWhere('o_id', '=', $val);
				}
			});
		}

		// Filter Gedcom content for any xref
		$pattern = '@' . Gedcom::REGEX_XREF . '@';
		$query->where('o_gedcom', 'RLIKE', $pattern);

		if ($sourceXrefFilter !== '') {
			$pattern2 = '@' . preg_quote(ltrim($sourceXrefFilter, '@'), '/') . '@';
			$query->where('o_gedcom', 'RLIKE', $pattern2);
		}

		$rows = $query->get();
		$treeName = $tree->name();
		foreach ($rows as $row) {
			$results[] = (object)[
				'record_xref'  => $row->record_xref,
				'gedcom'       => $row->gedcom,
				'tree_id'      => $row->tree_id,
				'tree_name'    => $treeName,
				'table_prefix' => $def->prefix,
				'found_in'     => 'other',
				'link'         => route('individual', ['xref' => $row->record_xref, 'tree' => $treeName]),
			];
		}

		// Shared notes (NOTE type from note table)
		if ($def->key === 'shared_note') {
			$results = array_merge($results, $this->fetchSharedNotes($tree, $xrefFilter, $sourceXrefFilter));
		}

		return $results;
	}

	/**
	 * @param Tree $tree
	 * @param array<string,int|null> $xrefFilter
	 * @param string $sourceXrefFilter
	 * @return array<object>
	 */
	private function fetchSharedNotes(Tree $tree, array $xrefFilter, string $sourceXrefFilter): array {
		$results = [];
		$pattern = '@' . Gedcom::REGEX_XREF . '@';

		$query = DB::table('note')
			->select('n_id as record_xref', 'n_gedcom as gedcom', 'n_file as tree_id')
			->where('n_file', '=', $tree->id());

		if ($xrefFilter !== []) {
			$query->where(function ($q) use ($xrefFilter) {
				foreach ($xrefFilter as $val) {
					$q->orWhere('n_id', '=', $val);
				}
			});
		}

		$query->where('n_gedcom', 'RLIKE', $pattern);

		if ($sourceXrefFilter !== '') {
			$pattern2 = '@' . preg_quote(ltrim($sourceXrefFilter, '@'), '/') . '@';
			$query->where('n_gedcom', 'RLIKE', $pattern2);
		}

		$rows = $query->get();
		$treeName = $tree->name();
		foreach ($rows as $row) {
			$results[] = (object)[
				'record_xref'  => $row->record_xref,
				'gedcom'       => $row->gedcom,
				'tree_id'      => $row->tree_id,
				'tree_name'    => $treeName,
				'table_prefix' => 'n',
				'found_in'     => 'note',
				'link'         => route('note', ['xref' => $row->record_xref, 'tree' => $treeName]),
			];
		}

		return $results;
	}

	/**
	 * @param string $table
	 * @param string $prefix
	 * @param Tree $tree
	 * @param array<string,int|null> $xrefFilter
	 * @param string $sourceXrefFilter
	 * @return array<object>
	 */
	private function fetchStandardTableRecords(string $table, string $prefix, Tree $tree, array $xrefFilter, string $sourceXrefFilter): array {
		$results = [];
		$idColumn = $prefix . '_id';
		$gedcomColumn = $prefix . '_gedcom';
		$pattern = '@' . Gedcom::REGEX_XREF . '@';

		$query = DB::table($table)
			->select($idColumn, $gedcomColumn)
			->where($prefix . '_file', '=', $tree->id());

		if ($xrefFilter !== []) {
			$query->where(function ($q) use ($xrefFilter, $idColumn) {
				foreach ($xrefFilter as $val) {
					$q->orWhere($idColumn, '=', $val);
				}
			});
		}

		$query->where($gedcomColumn, 'RLIKE', $pattern);

		if ($sourceXrefFilter !== '') {
			$pattern2 = '@' . preg_quote(ltrim($sourceXrefFilter, '@'), '/') . '@';
			$query->where($gedcomColumn, 'RLIKE', $pattern2);
		}

		$rows = $query->get();
		$treeName = $tree->name();
		$routeName = match ($prefix) {
			'i' => 'individual',
			'f' => 'family',
			's' => 'source',
			'm' => 'media',
			default => 'individual',
		};

		foreach ($rows as $row) {
			$results[] = (object)[
				'record_xref'  => $row->$idColumn,
				'gedcom'       => $row->$gedcomColumn,
				'tree_id'      => $tree->id(),
				'tree_name'    => $treeName,
				'table_prefix' => $prefix,
				'found_in'     => $table,
				'link'         => route($routeName, ['xref' => $row->$idColumn, 'tree' => $treeName]),
			];
		}

		return $results;
	}

	/**
	 * @param Tree $tree
	 * @param array<string,int|null> $xrefFilter
	 * @param string $sourceXrefFilter
	 * @return array<object>
	 */
	private function fetchHtmlBlocks(Tree $tree, array $xrefFilter, string $sourceXrefFilter): array {
		$results = [];
		$pattern = '@' . Gedcom::REGEX_XREF . '@';

		$query = DB::table('block')
			->select('block.block_id as record_xref', 'bs.setting_value as gedcom', 'block.gedcom_id as tree_id')
			->join('block_setting as bs', 'block.block_id', '=', 'bs.block_id')
			->where('block.gedcom_id', '=', $tree->id())
			->where('block.module_name', '=', 'html')
			->where('bs.setting_name', '=', 'html');

		if ($xrefFilter !== []) {
			$query->where(function ($q) use ($xrefFilter) {
				foreach ($xrefFilter as $val) {
					$q->orWhere('block.block_id', '=', $val);
				}
			});
		}

		$query->where('bs.setting_value', 'RLIKE', $pattern);

		if ($sourceXrefFilter !== '') {
			$pattern2 = '@' . preg_quote(ltrim($sourceXrefFilter, '@'), '/') . '@';
			$query->where('bs.setting_value', 'RLIKE', $pattern2);
		}

		$rows = $query->get();
		$treeName = $tree->name();
		foreach ($rows as $row) {
			$results[] = (object)[
				'record_xref'  => (string) $row->record_xref,
				'gedcom'       => $row->gedcom,
				'tree_id'      => $row->tree_id,
				'tree_name'    => $treeName,
				'table_prefix' => 'b',
				'found_in'     => 'block_setting',
				'link'         => route('module', ['module' => 'html', 'action' => 'BlockEditPage', 'tree' => $treeName, 'block_id' => $row->record_xref]),
			];
		}

		return $results;
	}

	/**
	 * Extract all xrefs from a Gedcom string.
	 * Returns array of ['xref', 'type', 'rectype', 'target_tree', 'target_tree_name'].
	 *
	 * @param string $gedcom
	 * @param RecordTypeDefinition $def
	 * @return array<array<string, string|null>>
	 */
	private function extractXrefsFromGedcom(string $gedcom, RecordTypeDefinition $def): array {
		$results = [];

		// Match enhanced links: #@wt=TYPE@XREF@treeName...
		// Captures: [1]=type (optional i/f/s/r/n/l), [2]=XREF, [3]=target_tree
		$patternEnhanced = '/#@wt=([ifsnrl])?@([A-Za-z0-9:_.-]+)@([^@\s&]*)/';
		preg_match_all($patternEnhanced, $gedcom, $matchesEnhanced, PREG_SET_ORDER);

		$enhancedXrefs = [];
		foreach ($matchesEnhanced as $m) {
			$enhancedXrefs[] = [
				'xref'            => '@' . $m[2] . '@',
				'type'            => 'enhanced',
				'rectype'         => $m[1] !== '' ? $m[1] : null,
				'target_tree'     => $m[3] !== '' ? $m[3] : null,
				'target_tree_name' => $this->resolveTreeName($m[3] !== '' ? $m[3] : null),
			];
		}

		// Match plain xrefs: @XREF@ (not preceded by # or =)
		$patternPlain = '/(?<!#)(?<![a-zA-Z0-9:])@([A-Za-z0-9:_.-]+)(?![a-zA-Z0-9:])@/';
		preg_match_all($patternPlain, $gedcom, $matchesPlain, PREG_SET_ORDER);

		$plainXrefs = [];
		foreach ($matchesPlain as $m) {
			$plainXrefs[] = [
				'xref'            => '@' . $m[1] . '@',
				'type'            => 'plain',
				'rectype'         => null,
				'target_tree'     => null,
				'target_tree_name' => null,
			];
		}

		// Merge and deduplicate
		$allXrefs = array_merge($enhancedXrefs, $plainXrefs);
		$seen = [];
		foreach ($allXrefs as $xref) {
			$key = $xref['xref'] . ($xref['target_tree'] ?? '');
			if (!isset($seen[$key])) {
				$seen[$key] = true;
				$results[] = $xref;
			}
		}

		return $results;
	}

	private function resolveTreeName(?string $treeName): ?string {
		if ($treeName === null || $treeName === '') {
			return null;
		}
		foreach ($this->trees as $tree) {
			if ($tree->name() === $treeName) {
				return $treeName;
			}
		}
		return null;
	}

	/**
	 * @param string $filter comma-separated xrefs like '@I1@,@F2@' or empty for all
	 * @return array<string,int|null>
	 */
	private function buildXrefFilter(string $filter): array {
		if ($filter === '') {
			return [];
		}
		$xrefs = array_filter(array_map('trim', explode(',', $filter)));
		return array_map(function ($x) {
			return ltrim($x, '@');
		}, $xrefs);
	}

	private function recordExistsInTree(string $xref, Tree $tree): bool {
		return Registry::gedcomRecordFactory()->make($xref, $tree) !== null;
	}

	/**
	 * Validate that the declared rectype matches the actual record type.
	 * Only applies to enhanced links with an explicit type.
	 *
	 * @param string|null $declaredType  'i', 'f', 's', 'r', 'n', 'l' (or null)
	 * @param string      $xref          e.g. '@I123@'
	 * @param Tree        $tree
	 * @return array{valid: bool, declared?: string, actual?: string}
	 */
	private function validateRecordType(?string $declaredType, string $xref, Tree $tree): array {
		if ($declaredType === null || $declaredType === '') {
			return ['valid' => true];
		}

		$record = Registry::gedcomRecordFactory()->make($xref, $tree);
		if ($record === null) {
			return ['valid' => true];
		}

		$typeMap = [
			'i' => Individual::RECORD_TYPE,
			'f' => Family::RECORD_TYPE,
			's' => Source::RECORD_TYPE,
			'r' => Repository::RECORD_TYPE,
			'n' => Note::RECORD_TYPE,
			'l' => Location::RECORD_TYPE,
		];

		$expectedType = $typeMap[$declaredType] ?? null;
		if ($expectedType === null) {
			return ['valid' => true];
		}

		$actualType = $record::RECORD_TYPE;
		$matches = ($expectedType === $actualType);

		return [
			'valid'    => $matches,
			'declared' => $this->resolveShortTypeToName($declaredType),
			'actual'   => $this->resolveRecordTypeToName($actualType),
		];
	}

	private function resolveShortTypeToName(string $shortType): string {
		$map = [
			'i' => 'Individual', 'f' => 'Family', 's' => 'Source',
			'r' => 'Repository', 'n' => 'Note', 'l' => 'Location',
		];
		return $map[$shortType] ?? $shortType;
	}

	private function resolveRecordTypeToName(string $recordType): string {
		$map = [
			'INDI'  => 'Individual',
			'FAM'   => 'Family',
			'SOUR'  => 'Source',
			'REPO'  => 'Repository',
			'NOTE'  => 'Note',
			'OBJE'  => 'Media object',
			'SNOTE' => 'Shared note',
			'SUBM'  => 'Submitter',
			'SUBN'  => 'Submission',
			'_LOC'  => 'Location',
			'HEAD'  => 'Header',
		];
		return $map[$recordType] ?? $recordType;
	}
}
