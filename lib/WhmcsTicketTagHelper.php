<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;

/**
 * Reads/writes WHMCS core ticket tags (Tag Cloud) when available.
 * Schema differs across WHMCS versions — columns are detected at runtime.
 */
class WhmcsTicketTagHelper
{
    public const AI_TAG_PREFIX = 'ai-';

    /** @var array<string,mixed>|null */
    private static $schemaCache;

    /** rel_type / type values seen in WHMCS builds */
    private const REL_TYPE_VALUES = [
        'ticket', 'Ticket', 'TICKET', 'support', 'Support', 'SupportTicket',
    ];

    /**
     * @return array<string,mixed>|null
     */
    public static function getSchema(): ?array
    {
        if (self::$schemaCache !== null) {
            return self::$schemaCache['ok'] ? self::$schemaCache['data'] : null;
        }

        self::$schemaCache = ['ok' => false, 'data' => null];

        $tagsTable = null;
        foreach (['tbltags', 'tbltag'] as $t) {
            if (Capsule::schema()->hasTable($t)) {
                $tagsTable = $t;
                break;
            }
        }
        $linksTable = null;
        foreach (['tbltaglinks', 'tbltag_links'] as $t) {
            if (Capsule::schema()->hasTable($t)) {
                $linksTable = $t;
                break;
            }
        }
        if (!$tagsTable || !$linksTable) {
            return null;
        }

        $tagCols  = Capsule::schema()->getColumnListing($tagsTable);
        $linkCols = Capsule::schema()->getColumnListing($linksTable);

        $lower = function ($c) {
            return strtolower((string) $c);
        };
        $tagColsL  = array_map($lower, $tagCols);
        $linkColsL = array_map($lower, $linkCols);

        $pick = function (array $candidates, array $colsL, array $orig) {
            foreach ($candidates as $cand) {
                $i = array_search(strtolower($cand), $colsL, true);
                if ($i !== false) {
                    return $orig[$i];
                }
            }
            return null;
        };

        $tagTextCol = $pick(['tag', 'name', 'title'], $tagColsL, $tagCols);
        $tagIdCol   = $pick(['id'], $tagColsL, $tagCols);
        if (!$tagTextCol || !$tagIdCol) {
            return null;
        }

        $linkTagIdCol = $pick(['tagid', 'tag_id'], $linkColsL, $linkCols);
        $linkRelIdCol = $pick(['relid', 'rel_id', 'entityid', 'entity_id', 'ticketid', 'ticket_id', 'object_id'], $linkColsL, $linkCols);
        $linkPkCol    = $pick(['id'], $linkColsL, $linkCols);
        $typeCol      = $pick(['rel_type', 'type', 'entity_type'], $linkColsL, $linkCols);

        if (!$linkTagIdCol || !$linkRelIdCol) {
            return null;
        }

        $data = [
            'tagsTable'   => $tagsTable,
            'linksTable'  => $linksTable,
            'tagTextCol'  => $tagTextCol,
            'tagIdCol'    => $tagIdCol,
            'linkTagCol'  => $linkTagIdCol,
            'linkRelCol'  => $linkRelIdCol,
            'linkPkCol'   => $linkPkCol ?: 'id',
            'typeCol'     => $typeCol,
            'typeValue'   => 'ticket',
        ];

        self::$schemaCache = ['ok' => true, 'data' => $data];
        return $data;
    }

    /**
     * @return string[] Tag display strings for this ticket (from WHMCS tables)
     */
    public static function getTagsForTicket(int $ticketId): array
    {
        $rows = self::getTagsForTickets([$ticketId]);
        return $rows[$ticketId] ?? [];
    }

    /**
     * @param int[] $ticketIds
     * @return array<int, string[]> ticket id => tag strings
     */
    public static function getTagsForTickets(array $ticketIds): array
    {
        $ticketIds = array_values(array_unique(array_filter(array_map('intval', $ticketIds))));
        $out = [];
        foreach ($ticketIds as $tid) {
            $out[$tid] = [];
        }
        if ($ticketIds === []) {
            return $out;
        }

        $s = self::getSchema();
        if ($s) {
            try {
                $q = Capsule::table($s['linksTable'] . ' as tl')
                    ->join($s['tagsTable'] . ' as tg', 'tl.' . $s['linkTagCol'], '=', 'tg.' . $s['tagIdCol'])
                    ->whereIn('tl.' . $s['linkRelCol'], $ticketIds)
                    ->orderBy('tg.' . $s['tagTextCol'], 'asc');

                if (!empty($s['typeCol'])) {
                    $q->whereIn('tl.' . $s['typeCol'], array_merge(self::REL_TYPE_VALUES, [1, 2]));
                }

                $rows = $q->get([
                    'tl.' . $s['linkRelCol'] . ' as _rel',
                    'tg.' . $s['tagTextCol'] . ' as tname',
                ]);

                foreach ($rows as $row) {
                    $rid = (int) ($row->_rel ?? 0);
                    $t   = trim((string) ($row->tname ?? ''));
                    if ($rid < 1 || $t === '' || !isset($out[$rid])) {
                        continue;
                    }
                    if (!in_array($t, $out[$rid], true)) {
                        $out[$rid][] = $t;
                    }
                }

                // If type filter hid rows (wrong enum), retry without type for tickets still empty
                if (!empty($s['typeCol'])) {
                    $still = [];
                    foreach ($ticketIds as $tid) {
                        if (empty($out[$tid])) {
                            $still[] = $tid;
                        }
                    }
                    if ($still !== []) {
                        $q2 = Capsule::table($s['linksTable'] . ' as tl')
                            ->join($s['tagsTable'] . ' as tg', 'tl.' . $s['linkTagCol'], '=', 'tg.' . $s['tagIdCol'])
                            ->whereIn('tl.' . $s['linkRelCol'], $still)
                            ->orderBy('tg.' . $s['tagTextCol'], 'asc');
                        foreach ($q2->get([
                            'tl.' . $s['linkRelCol'] . ' as _rel',
                            'tg.' . $s['tagTextCol'] . ' as tname',
                        ]) as $row) {
                            $rid = (int) ($row->_rel ?? 0);
                            $t   = trim((string) ($row->tname ?? ''));
                            if ($rid < 1 || $t === '' || !isset($out[$rid])) {
                                continue;
                            }
                            if (!in_array($t, $out[$rid], true)) {
                                $out[$rid][] = $t;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // tbltickettags pivot may still be readable
            }
        }

        if (self::resolveTicketTagPivotMeta() !== null) {
            try {
                self::fillTagsFromPivotRead($ticketIds, $out);
            } catch (\Throwable $e) {
            }
        }

        return $out;
    }

    /**
     * Writes tags into WHMCS Tag Cloud: tries local API, tbltickets.tags (if present), then tag/link tables.
     *
     * @param string[] $normalizedTags Already normalized (ai-* slugs)
     */
    public static function syncSahdevAiTags(int $ticketId, array $normalizedTags): void
    {
        if ($ticketId < 1) {
            return;
        }

        $normalizedTags = array_values(array_unique(array_filter($normalizedTags)));
        if (count($normalizedTags) > 8) {
            $normalizedTags = array_slice($normalizedTags, 0, 8);
        }

        try {
            self::tryLocalApiTicketTags($ticketId, $normalizedTags);
        } catch (\Throwable $e) {
        }

        try {
            self::tryPivotTicketTagTable($ticketId, $normalizedTags);
        } catch (\Throwable $e) {
        }

        try {
            self::tryPolymorphicTaggables($ticketId, $normalizedTags);
        } catch (\Throwable $e) {
        }

        try {
            self::tryTicketsTableTagColumns($ticketId, $normalizedTags);
        } catch (\Throwable $e) {
        }

        // Prefer tbltickettags when present; avoid also inserting tbltaglinks rows (duplicate tags in UI).
        if (self::resolveTicketTagPivotMeta() !== null) {
            return;
        }

        $s = self::getSchema();
        if (!$s) {
            return;
        }

        try {
            Capsule::connection()->transaction(function () use ($s, $ticketId, $normalizedTags) {
                self::removeAiPrefixLinks($s, $ticketId);

                foreach ($normalizedTags as $tagName) {
                    $tagId = self::getOrCreateTagId($s, $tagName);
                    if ($tagId === null) {
                        continue;
                    }
                    self::ensureLink($s, $tagId, $ticketId);
                }
            });
        } catch (\Throwable $e) {
            // Never break cron
        }
    }

    /**
     * @return array{tagsTable:string,tagTextCol:string,tagIdCol:string}|null
     */
    private static function resolveStandardTagsTable(): ?array
    {
        foreach (['tbltags', 'tbltag'] as $t) {
            if (!Capsule::schema()->hasTable($t)) {
                continue;
            }
            $cols = Capsule::schema()->getColumnListing($t);
            $lc   = array_map('strtolower', $cols);
            $pick = function (array $cands) use ($lc, $cols) {
                foreach ($cands as $c) {
                    $i = array_search(strtolower($c), $lc, true);
                    if ($i !== false) {
                        return $cols[$i];
                    }
                }
                return null;
            };
            $tagTextCol = $pick(['tag', 'name', 'title']);
            $tagIdCol   = $pick(['id']);
            if ($tagTextCol && $tagIdCol) {
                return [
                    'tagsTable'  => $t,
                    'tagTextCol' => $tagTextCol,
                    'tagIdCol'   => $tagIdCol,
                ];
            }
        }
        return null;
    }

    /**
     * Ticket ↔ tag pivot used by WHMCS Tag Cloud (`tbltickettags` on many installs) plus `tbltags` for label text.
     *
     * @return array{pivot:string,ticketCol:string,tagCol:string,cols:string[],meta:array{tagsTable:string,tagTextCol:string,tagIdCol:string}}|null
     */
    private static function resolveTicketTagPivotMeta(): ?array
    {
        $meta = self::resolveStandardTagsTable();
        if (!$meta) {
            return null;
        }

        foreach (['tbltickettags', 'tbl_ticket_tags', 'tbl_ticket_tag', 'tblticket_tags'] as $pivot) {
            if (!Capsule::schema()->hasTable($pivot)) {
                continue;
            }
            $cols = Capsule::schema()->getColumnListing($pivot);
            $lc   = array_map('strtolower', $cols);
            $pick = function (array $cands) use ($lc, $cols) {
                foreach ($cands as $c) {
                    $i = array_search(strtolower($c), $lc, true);
                    if ($i !== false) {
                        return $cols[$i];
                    }
                }
                return null;
            };
            $ticketCol = $pick(['ticket_id', 'ticketid', 'tid']);
            $tagCol    = $pick(['tag_id', 'tagid']);
            if (!$ticketCol || !$tagCol) {
                continue;
            }

            return [
                'pivot'     => $pivot,
                'ticketCol' => $ticketCol,
                'tagCol'    => $tagCol,
                'cols'      => $cols,
                'meta'      => $meta,
            ];
        }

        return null;
    }

    /**
     * Many WHMCS builds use a simple ticket_id ↔ tag_id pivot (Tag Cloud sidebar).
     *
     * @param string[] $tagNames
     */
    private static function tryPivotTicketTagTable(int $ticketId, array $tagNames): void
    {
        if ($tagNames === []) {
            return;
        }
        $pm = self::resolveTicketTagPivotMeta();
        if (!$pm) {
            return;
        }

        $pivot     = $pm['pivot'];
        $ticketCol = $pm['ticketCol'];
        $tagCol    = $pm['tagCol'];
        $cols      = $pm['cols'];
        $meta      = $pm['meta'];

        $aiIds = Capsule::table($meta['tagsTable'])
            ->where($meta['tagTextCol'], 'like', self::AI_TAG_PREFIX . '%')
            ->pluck($meta['tagIdCol'])
            ->toArray();
        if (!empty($aiIds)) {
            Capsule::table($pivot)
                ->where($ticketCol, $ticketId)
                ->whereIn($tagCol, $aiIds)
                ->delete();
        }

        foreach ($tagNames as $name) {
            $tagId = self::getOrCreateTagIdDirect($meta, $name);
            if ($tagId === null) {
                continue;
            }
            $exists = Capsule::table($pivot)
                ->where($ticketCol, $ticketId)
                ->where($tagCol, $tagId)
                ->exists();
            if ($exists) {
                continue;
            }
            $row = [$ticketCol => $ticketId, $tagCol => $tagId];
            if (in_array('created_at', $cols, true)) {
                $row['created_at'] = date('Y-m-d H:i:s');
            }
            if (in_array('updated_at', $cols, true)) {
                $row['updated_at'] = date('Y-m-d H:i:s');
            }
            try {
                Capsule::table($pivot)->insert($row);
            } catch (\Throwable $e) {
                // ignore duplicate / constraint
            }
        }
    }

    /**
     * When `tbltaglinks` is absent, WHMCS may still store tags via `tbltickettags` + `tbltags`.
     *
     * @param int[] $ticketIds
     * @param array<int, string[]> $out
     */
    private static function fillTagsFromPivotRead(array $ticketIds, array &$out): void
    {
        $pm = self::resolveTicketTagPivotMeta();
        if (!$pm) {
            return;
        }

        $m = $pm['meta'];
        try {
            $rows = Capsule::table($pm['pivot'] . ' as pt')
                ->join($m['tagsTable'] . ' as tg', 'pt.' . $pm['tagCol'], '=', 'tg.' . $m['tagIdCol'])
                ->whereIn('pt.' . $pm['ticketCol'], $ticketIds)
                ->orderBy('tg.' . $m['tagTextCol'], 'asc')
                ->get([
                    'pt.' . $pm['ticketCol'] . ' as _rel',
                    'tg.' . $m['tagTextCol'] . ' as tname',
                ]);
        } catch (\Throwable $e) {
            return;
        }

        foreach ($rows as $row) {
            $rid = (int) ($row->_rel ?? 0);
            $t   = trim((string) ($row->tname ?? ''));
            if ($rid < 1 || $t === '' || !isset($out[$rid])) {
                continue;
            }
            if (!in_array($t, $out[$rid], true)) {
                $out[$rid][] = $t;
            }
        }
    }

    /**
     * Polymorphic tag rows (taggable_id + taggable_type) used in some WHMCS versions.
     *
     * @param string[] $tagNames
     */
    private static function tryPolymorphicTaggables(int $ticketId, array $tagNames): void
    {
        if ($tagNames === []) {
            return;
        }
        $meta = self::resolveStandardTagsTable();
        if (!$meta) {
            return;
        }

        foreach (['tbltaggables', 'tbl_taggables'] as $table) {
            if (!Capsule::schema()->hasTable($table)) {
                continue;
            }
            $cols = Capsule::schema()->getColumnListing($table);
            $lc   = array_map('strtolower', $cols);
            $pick = function (array $cands) use ($lc, $cols) {
                foreach ($cands as $c) {
                    $i = array_search(strtolower($c), $lc, true);
                    if ($i !== false) {
                        return $cols[$i];
                    }
                }
                return null;
            };
            $tagIdCol   = $pick(['tag_id', 'tagid']);
            $relIdCol   = $pick(['taggable_id', 'entity_id', 'rel_id', 'ticket_id', 'ticketid']);
            $typeCol    = $pick(['taggable_type', 'entity_type', 'type', 'rel_type']);
            if (!$tagIdCol || !$relIdCol || !$typeCol) {
                continue;
            }

            $typeGuesses = array_merge(
                [
                    'ticket', 'Ticket', 'tickets', 'TICKET', 'support', 'Support',
                    'WHMCS\\Support\\Ticket', 'WHMCS\\Tickets\\Ticket', 'WHMCS\\Ticket\\Ticket',
                ],
                self::distinctColumnValues($table, $typeCol)
            );
            $typeGuesses = array_values(array_unique(array_filter($typeGuesses)));

            $aiIds = Capsule::table($meta['tagsTable'])
                ->where($meta['tagTextCol'], 'like', self::AI_TAG_PREFIX . '%')
                ->pluck($meta['tagIdCol'])
                ->toArray();
            if (!empty($aiIds)) {
                Capsule::table($table)
                    ->where($relIdCol, $ticketId)
                    ->whereIn($tagIdCol, $aiIds)
                    ->whereIn($typeCol, $typeGuesses)
                    ->delete();
            }

            foreach ($tagNames as $name) {
                $tid = self::getOrCreateTagIdDirect($meta, $name);
                if ($tid === null) {
                    continue;
                }
                foreach ($typeGuesses as $typeVal) {
                    $exists = Capsule::table($table)
                        ->where($relIdCol, $ticketId)
                        ->where($tagIdCol, $tid)
                        ->where($typeCol, $typeVal)
                        ->exists();
                    if ($exists) {
                        break;
                    }
                    $row = [
                        $tagIdCol   => $tid,
                        $relIdCol   => $ticketId,
                        $typeCol    => $typeVal,
                    ];
                    if (in_array('created_at', $cols, true)) {
                        $row['created_at'] = date('Y-m-d H:i:s');
                    }
                    if (in_array('updated_at', $cols, true)) {
                        $row['updated_at'] = date('Y-m-d H:i:s');
                    }
                    try {
                        Capsule::table($table)->insert($row);
                        break;
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            }
            return;
        }
    }

    /**
     * @return mixed[]
     */
    private static function distinctColumnValues(string $table, string $column): array
    {
        try {
            return Capsule::table($table)->whereNotNull($column)->distinct()->limit(40)->pluck($column)->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array{tagsTable:string,tagTextCol:string,tagIdCol:string} $meta
     */
    private static function getOrCreateTagIdDirect(array $meta, string $tagName): ?int
    {
        $tagName = substr($tagName, 0, 128);
        $existing = Capsule::table($meta['tagsTable'])
            ->where($meta['tagTextCol'], $tagName)
            ->value($meta['tagIdCol']);
        if ($existing !== null) {
            return (int) $existing;
        }
        $insert = [$meta['tagTextCol'] => $tagName];
        $tagTableCols = Capsule::schema()->getColumnListing($meta['tagsTable']);
        if (in_array('created_at', $tagTableCols, true)) {
            $insert['created_at'] = date('Y-m-d H:i:s');
        }
        if (in_array('updated_at', $tagTableCols, true)) {
            $insert['updated_at'] = date('Y-m-d H:i:s');
        }
        try {
            return (int) Capsule::table($meta['tagsTable'])->insertGetId($insert);
        } catch (\Throwable $e) {
            $existing = Capsule::table($meta['tagsTable'])
                ->where($meta['tagTextCol'], $tagName)
                ->value($meta['tagIdCol']);
            return $existing !== null ? (int) $existing : null;
        }
    }

    /**
     * Learn rel_type / type values already stored so inserts match the UI.
     *
     * @param array<string,mixed> $s
     * @return mixed[]
     */
    private static function inferLinkTypeValues(array $s): array
    {
        if (empty($s['typeCol'])) {
            return [];
        }
        try {
            $fromDb = Capsule::table($s['linksTable'])
                ->whereNotNull($s['typeCol'])
                ->distinct()
                ->limit(40)
                ->pluck($s['typeCol'])
                ->toArray();
            $clean = [];
            foreach ($fromDb as $v) {
                if ($v !== null && $v !== '') {
                    $clean[] = $v;
                }
            }
            return array_values(array_unique(array_merge(self::REL_TYPE_VALUES, [1, 2], $clean)));
        } catch (\Throwable $e) {
            return array_merge(self::REL_TYPE_VALUES, [1, 2]);
        }
    }

    /**
     * Some WHMCS builds accept tags via internal API (undocumented; safe to attempt).
     *
     * @param string[] $tagNames
     */
    private static function tryLocalApiTicketTags(int $ticketId, array $tagNames): void
    {
        if (!function_exists('localAPI') || $tagNames === []) {
            return;
        }

        $csv = implode(',', $tagNames);
        $adminUser = '';
        try {
            if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['adminid'])) {
                $u = Capsule::table('tbladmins')->where('id', (int) $_SESSION['adminid'])->value('username');
                if (is_string($u) && $u !== '') {
                    $adminUser = $u;
                }
            }
        } catch (\Throwable $e) {
        }

        $post = [
            'ticketid' => $ticketId,
            'tags'     => $csv,
        ];
        @localAPI('UpdateTicket', $post, $adminUser);

        // Alternate parameter name seen in some forks / minor versions
        @localAPI('UpdateTicket', [
            'ticketid' => $ticketId,
            'tag'      => $csv,
        ], $adminUser);
    }

    /**
     * Updates every string-like column on tbltickets whose name contains "tag" (e.g. tags, tag_list).
     *
     * @param string[] $tagNames
     */
    private static function tryTicketsTableTagColumns(int $ticketId, array $tagNames): void
    {
        if ($tagNames === [] || !Capsule::schema()->hasTable('tbltickets')) {
            return;
        }

        $cols = Capsule::schema()->getColumnListing('tbltickets');
        $allowedNames = ['tags', 'tag', 'tag_list', 'taglist', 'tickettags'];
        foreach ($cols as $col) {
            $l = strtolower((string) $col);
            if (!in_array($l, $allowedNames, true)) {
                continue;
            }
            try {
                $type = Capsule::schema()->getColumnType('tbltickets', $col);
            } catch (\Throwable $e) {
                continue;
            }
            $tl = strtolower((string) $type);
            if (strpos($tl, 'char') === false && strpos($tl, 'text') === false && strpos($tl, 'string') === false) {
                continue;
            }

            $current = trim((string) (Capsule::table('tbltickets')->where('id', $ticketId)->value($col) ?? ''));
            $parts   = $current === '' ? [] : array_map('trim', explode(',', $current));

            $keep = [];
            foreach ($parts as $p) {
                if ($p === '') {
                    continue;
                }
                if (preg_match('/^ai[-\s]/i', $p)) {
                    continue;
                }
                $keep[] = $p;
            }

            $merged = array_values(array_unique(array_merge($keep, $tagNames)));
            try {
                Capsule::table('tbltickets')->where('id', $ticketId)->update([
                    $col => implode(',', $merged),
                ]);
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    /**
     * @param array<string,mixed> $s
     */
    private static function removeAiPrefixLinks(array $s, int $ticketId): void
    {
        $linkCol = $s['linkRelCol'];

        $q = Capsule::table($s['linksTable'] . ' as tl')
            ->join($s['tagsTable'] . ' as tg', 'tl.' . $s['linkTagCol'], '=', 'tg.' . $s['tagIdCol'])
            ->where('tl.' . $linkCol, $ticketId)
            ->where('tg.' . $s['tagTextCol'], 'like', self::AI_TAG_PREFIX . '%');

        if (!empty($s['typeCol'])) {
            $q->whereIn('tl.' . $s['typeCol'], self::inferLinkTypeValues($s));
        }

        $pk = $s['linkPkCol'] ?? 'id';
        $ids = $q->pluck('tl.' . $pk)->filter()->unique()->values()->toArray();
        if (empty($ids)) {
            return;
        }

        Capsule::table($s['linksTable'])->whereIn($pk, $ids)->delete();
    }

    /**
     * @param array<string,mixed> $s
     */
    private static function getOrCreateTagId(array $s, string $tagName): ?int
    {
        $tagName = substr($tagName, 0, 128);
        $existing = Capsule::table($s['tagsTable'])
            ->where($s['tagTextCol'], $tagName)
            ->value($s['tagIdCol']);

        if ($existing !== null) {
            return (int) $existing;
        }

        $insert = [$s['tagTextCol'] => $tagName];
        $tagTableCols = Capsule::schema()->getColumnListing($s['tagsTable']);
        if (in_array('created_at', $tagTableCols, true)) {
            $insert['created_at'] = date('Y-m-d H:i:s');
        }
        if (in_array('updated_at', $tagTableCols, true)) {
            $insert['updated_at'] = date('Y-m-d H:i:s');
        }

        try {
            return (int) Capsule::table($s['tagsTable'])->insertGetId($insert);
        } catch (\Throwable $e) {
            $existing = Capsule::table($s['tagsTable'])
                ->where($s['tagTextCol'], $tagName)
                ->value($s['tagIdCol']);
            return $existing !== null ? (int) $existing : null;
        }
    }

    /**
     * @param array<string,mixed> $s
     */
    private static function ensureLink(array $s, int $tagId, int $ticketId): void
    {
        $q = Capsule::table($s['linksTable'])
            ->where($s['linkTagCol'], $tagId)
            ->where($s['linkRelCol'], $ticketId);

        if (!empty($s['typeCol'])) {
            $q->whereIn($s['typeCol'], self::inferLinkTypeValues($s));
        }

        if ($q->exists()) {
            return;
        }

        $baseRow = [
            $s['linkTagCol'] => $tagId,
            $s['linkRelCol'] => $ticketId,
        ];

        $linkTableCols = Capsule::schema()->getColumnListing($s['linksTable']);
        $timestamps = [];
        if (in_array('created_at', $linkTableCols, true)) {
            $timestamps['created_at'] = date('Y-m-d H:i:s');
        }
        if (in_array('updated_at', $linkTableCols, true)) {
            $timestamps['updated_at'] = date('Y-m-d H:i:s');
        }

        if (empty($s['typeCol'])) {
            try {
                Capsule::table($s['linksTable'])->insert(array_merge($baseRow, $timestamps));
            } catch (\Throwable $e) {
                // ignore
            }
            return;
        }

        $typeAttempts = self::inferLinkTypeValues($s);
        foreach ($typeAttempts as $typeVal) {
            $row = array_merge($baseRow, [$s['typeCol'] => $typeVal], $timestamps);
            try {
                Capsule::table($s['linksTable'])->insert($row);
                return;
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    /**
     * @param mixed $raw
     * @return string[]
     */
    public static function normalizeTagList($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                $raw = array_map('trim', explode(',', $raw));
            }
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_array($item) || is_object($item)) {
                continue;
            }
            $t = strtolower(trim((string) $item));
            $t = preg_replace('/[^a-z0-9\-]/', '', str_replace(' ', '-', $t)) ?? '';
            if ($t === '') {
                continue;
            }
            if (strpos($t, self::AI_TAG_PREFIX) !== 0) {
                $t = self::AI_TAG_PREFIX . $t;
            }
            if (strlen($t) > 64) {
                $t = substr($t, 0, 64);
            }
            if (preg_match('/^' . preg_quote(self::AI_TAG_PREFIX, '/') . '[a-z0-9\-]{1,60}$/', $t)) {
                $out[] = $t;
            }
        }

        return array_values(array_unique($out));
    }
}
