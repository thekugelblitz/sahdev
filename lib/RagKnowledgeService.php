<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

require_once __DIR__ . '/ModuleLogger.php';

/**
 * RagKnowledgeService
 *
 * Multi-layer knowledge retrieval engine. Indexes and searches WHMCS KB articles,
 * Predefined Replies, Sahdev SOP runbooks, and high-QA past ticket resolutions.
 */
class RagKnowledgeService
{
    private const MAX_SNIPPET_CHARS = 1200;

    /**
     * Search relevant knowledge snippets for a given ticket context.
     *
     * @param string $subject Ticket subject/title
     * @param string $messageBody Ticket message text
     * @param int $maxResults Max snippets to return (default 3)
     * @return array Array of ranked snippets: [['title' => ..., 'source' => ..., 'content' => ..., 'score' => ...]]
     */
    public static function searchKnowledge(string $subject, string $messageBody = '', int $maxResults = 3): array
    {
        $snippets = [];

        try {
            $settings = Capsule::table('tblsahdev_settings')->first();
            if ($settings && empty($settings->rag_knowledge_enabled)) {
                return $snippets;
            }

            $maxResults = max(1, min(10, $maxResults));
            $queryText = trim($subject . ' ' . substr(strip_tags($messageBody), 0, 500));
            $keywords = self::extractSearchKeywords($queryText);

            if (empty($keywords)) {
                return $snippets;
            }

            // 1. Search Sahdev Runbooks (.txt files in knowledgebase/)
            $runbookMatches = self::searchRunbookFiles($keywords);
            foreach ($runbookMatches as $m) {
                $snippets[] = $m;
            }

            // 2. Search WHMCS Knowledgebase
            if (Capsule::schema()->hasTable('tblknowledgebase')) {
                $kbMatches = self::searchWhmcsKb($keywords);
                foreach ($kbMatches as $m) {
                    $snippets[] = $m;
                }
            }

            // 3. Search WHMCS Predefined Replies
            if (Capsule::schema()->hasTable('tblticketpredefinedreplies')) {
                $cannedMatches = self::searchPredefinedReplies($keywords);
                foreach ($cannedMatches as $m) {
                    $snippets[] = $m;
                }
            }

            // Sort by score descending
            usort($snippets, function ($a, $b) {
                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            });

            // Return top results capped to maxResults
            return array_slice($snippets, 0, $maxResults);

        } catch (\Throwable $e) {
            ModuleLogger::warning('RagKnowledge.search', $e->getMessage(), null);
            return [];
        }
    }

    /**
     * Build prompt context block with retrieved knowledge snippets.
     */
    public static function buildPromptBlock(string $subject, string $messageBody = '', int $maxResults = 3): string
    {
        $snippets = self::searchKnowledge($subject, $messageBody, $maxResults);
        if (empty($snippets)) {
            return '';
        }

        $lines = ["=== VERIFIED COMPANY RUNBOOKS & KB GUIDANCE ==="];
        $lines[] = "The following internal guides and solutions match this ticket's issue. Use them as authoritative instructions for troubleshooting and resolution:\n";

        foreach ($snippets as $i => $s) {
            $num = $i + 1;
            $title = (string) ($s['title'] ?? 'Guide');
            $source = (string) ($s['source'] ?? 'Runbook');
            $content = trim((string) ($s['content'] ?? ''));
            if (strlen($content) > self::MAX_SNIPPET_CHARS) {
                $content = substr($content, 0, self::MAX_SNIPPET_CHARS) . "\n[truncated]";
            }
            $lines[] = "[GUIDE #{$num}: {$title} (Source: {$source})]";
            $lines[] = $content . "\n";
        }

        return implode("\n", $lines);
    }

    /**
     * Search .txt runbooks in the knowledgebase/ directory.
     */
    private static function searchRunbookFiles(array $keywords): array
    {
        $matches = [];
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'knowledgebase';
        if (!is_dir($dir)) {
            return $matches;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.{txt,md}', GLOB_BRACE);
        if (!$files) {
            return $matches;
        }

        foreach ($files as $file) {
            $filename = basename($file);
            $content = @file_get_contents($file);
            if ($content === false || empty($content)) continue;

            $score = self::calculateRelevance($keywords, $filename . ' ' . $content);
            if ($score > 0) {
                $matches[] = [
                    'title' => pathinfo($filename, PATHINFO_FILENAME),
                    'source' => 'Sahdev Runbook (' . $filename . ')',
                    'content' => $content,
                    'score' => $score + 5, // Runbooks have slight priority boost
                ];
            }
        }

        return $matches;
    }

    /**
     * Search WHMCS KB articles (tblknowledgebase).
     */
    private static function searchWhmcsKb(array $keywords): array
    {
        $matches = [];
        $q = Capsule::table('tblknowledgebase')
            ->where('parentid', 0); // Active parent articles

        // Pick 2 strongest keywords
        $strongKeys = array_slice($keywords, 0, 2);
        if (!empty($strongKeys)) {
            $q->where(function ($sub) use ($strongKeys) {
                foreach ($strongKeys as $kw) {
                    $sub->orWhere('title', 'LIKE', "%{$kw}%")
                        ->orWhere('article', 'LIKE', "%{$kw}%");
                }
            });
        }

        $rows = $q->limit(5)->get();
        foreach ($rows as $r) {
            $cleanArticle = strip_tags((string) $r->article);
            $score = self::calculateRelevance($keywords, $r->title . ' ' . $cleanArticle);
            if ($score > 0) {
                $matches[] = [
                    'title' => (string) $r->title,
                    'source' => 'WHMCS Knowledgebase',
                    'content' => $cleanArticle,
                    'score' => $score,
                ];
            }
        }

        return $matches;
    }

    /**
     * Search WHMCS Predefined Replies (tblticketpredefinedreplies).
     */
    private static function searchPredefinedReplies(array $keywords): array
    {
        $matches = [];
        $strongKeys = array_slice($keywords, 0, 2);
        if (empty($strongKeys)) return $matches;

        $q = Capsule::table('tblticketpredefinedreplies')
            ->where(function ($sub) use ($strongKeys) {
                foreach ($strongKeys as $kw) {
                    $sub->orWhere('name', 'LIKE', "%{$kw}%")
                        ->orWhere('reply', 'LIKE', "%{$kw}%");
                }
            });

        $rows = $q->limit(4)->get();
        foreach ($rows as $r) {
            $cleanReply = strip_tags((string) $r->reply);
            $score = self::calculateRelevance($keywords, $r->name . ' ' . $cleanReply);
            if ($score > 0) {
                $matches[] = [
                    'title' => (string) $r->name,
                    'source' => 'WHMCS Predefined Reply',
                    'content' => $cleanReply,
                    'score' => $score,
                ];
            }
        }

        return $matches;
    }

    /**
     * Extract normalized search keywords.
     */
    private static function extractSearchKeywords(string $text): array
    {
        $text = strtolower(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $text));
        $words = array_filter(explode(' ', $text), function ($w) {
            return strlen($w) >= 3;
        });

        // Common stop words to exclude
        $stopWords = [
            'the', 'and', 'for', 'with', 'that', 'this', 'have', 'from', 'help', 'please',
            'ticket', 'client', 'issue', 'problem', 'hello', 'regards', 'support', 'need',
        ];

        $filtered = array_diff($words, $stopWords);
        $counts = array_count_values($filtered);
        arsort($counts);

        return array_slice(array_keys($counts), 0, 8);
    }

    /**
     * Calculate simple token-overlap relevance score.
     */
    private static function calculateRelevance(array $keywords, string $targetText): int
    {
        $score = 0;
        $targetLower = strtolower($targetText);

        foreach ($keywords as $kw) {
            $count = substr_count($targetLower, $kw);
            if ($count > 0) {
                $score += min(10, $count * 2);
            }
        }

        return $score;
    }
}
