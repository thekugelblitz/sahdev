<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;

/**
 * Reads/writes WHMCS core ticket tags (Tag Cloud) when available.
 * Schema differs slightly across WHMCS versions — columns are detected at runtime.
 */
class WhmcsTicketTagHelper
{
    public const AI_TAG_PREFIX = 'ai-';

    /** @var array<string,mixed>|null */
    private static $schemaCache;

    /**
     * @return array<string,mixed>|null  keys: tagsTable, linksTable, tagTextCol, tagIdCol, linkIdCol, relIdCol, typeCol, typeValue
     */
    public static function getSchema(): ?array
    {
        if (self::$schemaCache !== null) {
            return self::$schemaCache['ok'] ? self::$schemaCache['data'] : null;
        }

        self::$schemaCache = ['ok' => false, 'data' => null];

        if (!Capsule::schema()->hasTable('tbltags') || !Capsule::schema()->hasTable('tbltaglinks')) {
            return null;
        }

        $tagCols  = Capsule::schema()->getColumnListing('tbltags');
        $linkCols = Capsule::schema()->getColumnListing('tbltaglinks');

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
        $linkRelIdCol = $pick(['relid', 'rel_id', 'entityid', 'entity_id'], $linkColsL, $linkCols);
        $linkPkCol    = $pick(['id'], $linkColsL, $linkCols);
        $typeCol      = $pick(['rel_type', 'type', 'entity_type'], $linkColsL, $linkCols);

        if (!$linkTagIdCol || !$linkRelIdCol) {
            return null;
        }

        $data = [
            'tagsTable'   => 'tbltags',
            'linksTable'  => 'tbltaglinks',
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
        $s = self::getSchema();
        if (!$s || $ticketId < 1) {
            return [];
        }

        try {
            $q = Capsule::table($s['linksTable'] . ' as tl')
                ->join($s['tagsTable'] . ' as tg', 'tl.' . $s['linkTagCol'], '=', 'tg.' . $s['tagIdCol'])
                ->where('tl.' . $s['linkRelCol'], $ticketId);

            if (!empty($s['typeCol'])) {
                $q->whereIn('tl.' . $s['typeCol'], ['ticket', 'Ticket', 'support']);
            }

            $rows = $q->orderBy('tg.' . $s['tagTextCol'], 'asc')
                ->get(['tg.' . $s['tagTextCol'] . ' as tname']);

            $out = [];
            foreach ($rows as $row) {
                $t = trim((string) ($row->tname ?? ''));
                if ($t !== '' && !in_array($t, $out, true)) {
                    $out[] = $t;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Replace Sahdev-managed tags on this ticket (tag names starting with "ai-") and attach new ones.
     *
     * @param string[] $normalizedTags Already normalized (ai-* slugs)
     */
    public static function syncSahdevAiTags(int $ticketId, array $normalizedTags): void
    {
        $s = self::getSchema();
        if (!$s || $ticketId < 1) {
            return;
        }

        $normalizedTags = array_values(array_unique(array_filter($normalizedTags)));
        if (count($normalizedTags) > 8) {
            $normalizedTags = array_slice($normalizedTags, 0, 8);
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
            $q->whereIn('tl.' . $s['typeCol'], ['ticket', 'Ticket', 'support']);
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
            // Race: fetch again
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
            $q->whereIn($s['typeCol'], ['ticket', 'Ticket', 'support']);
        }

        if ($q->exists()) {
            return;
        }

        $row = [
            $s['linkTagCol'] => $tagId,
            $s['linkRelCol'] => $ticketId,
        ];

        if (!empty($s['typeCol'])) {
            $row[$s['typeCol']] = 'ticket';
        }

        $linkTableCols = Capsule::schema()->getColumnListing($s['linksTable']);
        if (in_array('created_at', $linkTableCols, true)) {
            $row['created_at'] = date('Y-m-d H:i:s');
        }
        if (in_array('updated_at', $linkTableCols, true)) {
            $row['updated_at'] = date('Y-m-d H:i:s');
        }

        try {
            Capsule::table($s['linksTable'])->insert($row);
        } catch (\Throwable $e) {
            if (!empty($s['typeCol']) && isset($row[$s['typeCol']]) && $row[$s['typeCol']] === 'ticket') {
                $row[$s['typeCol']] = 'Ticket';
                try {
                    Capsule::table($s['linksTable'])->insert($row);
                } catch (\Throwable $e2) {
                    // ignore
                }
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
