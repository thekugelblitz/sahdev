<?php
namespace App\Services;

/** Shared by the application and the standalone connector packager. */
final class MigrationRegister
{
    public const STATUSES = [
        'pending' => ['label' => 'Not migrated', 'description' => 'Required functionality is not accepted in the cloud distribution. Legacy code may exist.'],
        'partial' => ['label' => 'Partial', 'description' => 'Some working pieces exist; migration or acceptance requirements remain.'],
        'implemented' => ['label' => 'Implemented', 'description' => 'Code exists. This is not a claim of complete production acceptance.'],
        'awaiting_live_validation' => ['label' => 'Needs live validation', 'description' => 'Needs real integration or operational evidence before acceptance.'],
        'verified' => ['label' => 'Verified', 'description' => 'Accepted with versioned evidence covering successful and failed paths.'],
    ];

    public static function complete(array $register): bool
    {
        if (empty($register['features']) || empty($register['journeys'])) return false;
        foreach ($register['features'] as $feature) {
            if (!self::accepted($feature) || empty($feature['capabilities'])) return false;
            foreach ($feature['capabilities'] as $capability) {
                if (!self::accepted($capability)) return false;
            }
        }
        foreach ($register['journeys'] as $journey) {
            if (!self::accepted($journey)) return false;
        }
        return true;
    }

    private static function accepted(array $item): bool
    {
        if (($item['status'] ?? null) !== 'verified' || empty($item['evidence'])) return false;
        foreach ($item['evidence'] as $record) {
            if (!is_array($record)) return false;
            foreach (['reference', 'reviewer', 'reviewed_on', 'environment', 'version'] as $field) {
                if (!is_string($record[$field] ?? null) || trim($record[$field]) === '') return false;
            }
        }
        return true;
    }
}
