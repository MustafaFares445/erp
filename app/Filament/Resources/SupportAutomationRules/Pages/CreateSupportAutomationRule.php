<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportAutomationRules\Pages;

use App\Filament\Resources\SupportAutomationRules\SupportAutomationRuleResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateSupportAutomationRule extends CreateRecord
{
    protected static string $resource = SupportAutomationRuleResource::class;

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return self::normalizeJsonValues($data);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function normalizeJsonValues(array $data): array
    {
        $conditions = $data['conditions'] ?? [];

        if (! is_array($conditions)) {
            return $data;
        }

        foreach ($conditions as $index => $condition) {
            if (! is_array($condition)) {
                continue;
            }
            if (! is_string($condition['value'] ?? null)) {
                continue;
            }
            $trimmed = mb_trim($condition['value']);
            if (str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $condition['value'] = $decoded;
                    $conditions[$index] = $condition;
                }
            }
        }

        if (array_key_exists('conditions', $data)) {
            $data['conditions'] = $conditions;
        }

        return $data;
    }
}
