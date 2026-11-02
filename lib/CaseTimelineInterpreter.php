<?php
namespace Sahdev\Lib;

/** Extractive presentation only. Does not infer that a promised action was performed. */
class CaseTimelineInterpreter
{
    public static function message(string $raw, bool $staff, bool $opening = false): array
    {
        $text = preg_replace('~<(?:br\s*/?|/p|/div|/li)>~i', "\n", mb_substr($raw, 0, 12000));
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\xc2\xa0"], ["\n", "\n", ' '], $text);
        // Cut only recognizable signature/quoted-history boundaries, not ordinary separators.
        $text = preg_split('/^\s*(?:best regards[!,]?|kind regards[!,]?|regards[!,]?|warm regards[!,]?|sincerely[!,]?|sent from my .+|on .{5,160} wrote:|[-_]{3,}\s*original message\s*[-_]*|our chatgpt support assistant:).*$/mi', $text, 2)[0];
        $lines = array_filter(explode("\n", $text), static function ($line) {
            return !preg_match('/^\s*(?:>.*|\\\\?[-_]{3,}|(?:hi|hello|dear)(?:\s+[\p{L} .-]{1,60})?[,!]?)\s*$/iu', $line);
        });
        $text = CaseTimeline::clean(trim(implode("\n", $lines)));
        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])|\n+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $ranked = [];
        foreach ($sentences as $index => $sentence) {
            $sentence = trim($sentence);
            if (preg_match('/^(?:thank you for (?:your patience|contacting|reaching)|kindly wait|we (?:will|shall) update you|please (?:bear with|wait))/i', $sentence)) continue;
            $score = preg_match('/\b(request|withdrawal|escalat\w*|revalidat\w*|validat\w*|offer|bonus|prefer|choose|let us know|error|fail\w*|not working|unable|resolved|refund|credit|balance|pending|check\w*|need|confirm\w*)\b/i', $sentence) ? 3 : 1;
            if(preg_match('/\b(?:prefer|choose|let us know|please (?:share|provide|send))\b/i',$sentence))$score=6;
            elseif(preg_match('/\b(?:bonus|offer|revalidat\w*|pending|escalat\w*)\b/i',$sentence))$score=5;
            $ranked[] = ['index'=>$index, 'score'=>$score, 'text'=>mb_strimwidth($sentence, 0, 300, '…')];
        }
        usort($ranked, static function ($a, $b) { return ($b['score'] <=> $a['score']) ?: ($a['index'] <=> $b['index']); });
        $ranked = array_slice($ranked, 0, 3);
        usort($ranked, static function ($a, $b) { return $a['index'] <=> $b['index']; });
        $highlights = array_column($ranked, 'text');
        $title = $opening ? 'Request opened' : ($staff ? 'Staff update' : 'Client update');
        if (!$opening) {
            if (preg_match('/\b(?:prefer|choose|let us know if|would you like)\b/i', $text)) $title = 'Options & next steps';
            elseif (preg_match('/\bescalat\w*\b/i', $text)) $title = 'Escalation update';
            elseif (preg_match('/\b(?:revalidat\w*|validat\w*|reviewing|investigat\w*)\b/i', $text)) $title = 'Review update';
            elseif (preg_match('/\b(?:please (?:share|provide|send)|could you|may I know)\b/i', $text)) $title = 'Information requested';
        }
        return ['title'=>$title, 'highlights'=>$highlights, 'summary'=>implode("\n", $highlights) ?: ($staff?'Staff reply recorded':'Client reply received'), 'excerpt'=>$text, 'basis'=>'Selected source excerpts; not independent verification'];
    }

    public static function tool(string $summary, string $status): array
    {
        $data = json_decode($summary, true);
        $title = in_array($status, ['failed','error','unavailable'], true) ? 'Check unavailable' : 'Diagnostic observation';
        if (!is_array($data)) {
            $readable = preg_match('/^\s*[\[{]/',$summary) ? 'Stored structured output is incomplete. Inspect the original evidence in Tools before interpreting this check.' : CaseTimeline::clean($summary);
            return ['title'=>$title, 'highlights'=>[$readable], 'summary'=>$readable];
        }
        $facts = [];
        $walk = static function ($value, $prefix = '', $depth = 0) use (&$walk, &$facts) {
            if ($depth > 4 || count($facts) >= 6) return;
            if (is_array($value)) { foreach ($value as $key=>$v) $walk($v, trim($prefix.' '.(is_numeric($key)?'':str_replace('_',' ', $key))), $depth+1); }
            elseif ($value !== null && $value !== '') $facts[] = mb_strimwidth(CaseTimeline::clean(($prefix?$prefix.': ':'').(is_bool($value)?($value?'yes':'no'):(string)$value)),0,300,'…');
        };
        $walk($data['facts'] ?? []);
        if (!empty($data['target']) && is_scalar($data['target'])) array_unshift($facts, 'Target: '.mb_strimwidth(CaseTimeline::clean((string)$data['target']),0,200,'…'));
        return ['title'=>!empty($data['tool'])&&is_scalar($data['tool'])?str_replace(['toolsapi_','_'],['',' '],mb_strimwidth(CaseTimeline::clean((string)$data['tool']),0,100,'…')).' · '.$title:$title, 'highlights'=>$facts ?: [$title], 'summary'=>implode("\n", $facts) ?: $title];
    }
}
