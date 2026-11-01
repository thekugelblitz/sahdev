<?php
namespace Sahdev\Lib;

/** Deterministic, contract-based evidence. Never infer health from missing fields. */
class DiagnosticEvidence
{
    public static function decode(string $path, string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data) || isset($data['error']) && $data['error'] !== null && $data['error'] !== '') {
            return ['valid' => false, 'error' => 'Invalid or unavailable diagnostic response'];
        }
        static $spec;
        if ($spec === null) {
            $spec = json_decode((string) file_get_contents(dirname(__DIR__) . '/toolsapi-2hs-in-openapi.json'), true) ?: [];
        }
        foreach ($spec['paths'] ?? [] as $pattern => $ops) {
            $regex = '#^' . preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote($pattern, '#')) . '$#';
            if ($pattern !== $path && !preg_match($regex, $path)) continue;
            $schema = $ops['get']['responses']['200']['content']['application/json']['schema'] ?? [];
            if (!self::matches($data, $schema, $spec)) {
                return ['valid' => false, 'error' => 'Diagnostic response does not match its published contract'];
            }
            return ['valid' => true, 'data' => $data];
        }
        return ['valid' => false, 'error' => 'No approved diagnostic response contract'];
    }

    private static function matches($value, array $schema, array $spec, int $depth = 0): bool
    {
        if ($depth > 14) return false;
        if (isset($schema['$ref'])) {
            $name = basename($schema['$ref']);
            return isset($spec['components']['schemas'][$name]) && self::matches($value, $spec['components']['schemas'][$name], $spec, $depth + 1);
        }
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $s) if (self::matches($value, $s, $spec, $depth + 1)) return true;
            return false;
        }
        $type = $schema['type'] ?? '';
        if ($type === 'null') return $value === null;
        if ($type === 'boolean' && !is_bool($value)) return false;
        if ($type === 'string' && !is_string($value)) return false;
        if ($type === 'integer' && !is_int($value)) return false;
        if ($type === 'number' && !is_numeric($value)) return false;
        if (in_array($type, ['object', 'array'], true) && !is_array($value)) return false;
        foreach ($schema['required'] ?? [] as $key) if (!array_key_exists($key, $value)) return false;
        foreach ($schema['properties'] ?? [] as $key => $s) {
            if (is_array($value) && array_key_exists($key, $value) && !self::matches($value[$key], $s, $spec, $depth + 1)) return false;
        }
        if ($type === 'array') foreach ($value as $v) if (!self::matches($v, $schema['items'] ?? [], $spec, $depth + 1)) return false;
        return true;
    }

    public static function summary(string $tool, string $path, string $body, string $target): string
    {
        $result = self::decode($path, $body);
        if (!$result['valid']) return 'Unverified: ' . $result['error'];
        $data = $result['data'];
        $metadata = $data['metadata'] ?? [];
        unset($data['metadata']);
        $evidence = ['target' => $target, 'tool' => $tool, 'outcome' => 'observed',
            'observed_at' => $metadata['timestamp'] ?? null, 'upstream_cached' => $metadata['cached'] ?? null,
            'duration_ms' => $metadata['duration_ms'] ?? null, 'facts' => self::compact($data)];
        $json = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($json) > 4000) {
            $evidence['facts'] = ['partial' => true, 'reason' => 'Result exceeds evidence budget; inspect admin raw output'];
            $json = json_encode($evidence, JSON_UNESCAPED_SLASHES);
        }
        return $json;
    }

    private static function compact($value, int $depth = 0)
    {
        if (is_string($value)) return mb_substr($value, 0, 450);
        if (!is_array($value)) return $value;
        if ($depth > 5) return '[nested details omitted]';
        $out = [];
        foreach (array_slice($value, 0, 16, true) as $key => $v) {
            if (in_array($key, ['raw_output', 'raw_text', 'screenshot_url'], true)) continue;
            $out[$key] = self::compact($v, $depth + 1);
        }
        if (count($value) > 16) $out['_truncated'] = true;
        return $out;
    }
}
