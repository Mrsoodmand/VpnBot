<?php

namespace App\Services;

class BotKeyboardLayout
{
    /** Pair adjacent short buttons without moving navigation or spanning actions. */
    public function compact(array $rows): array
    {
        $result = [];
        $pending = null;

        foreach ($rows as $row) {
            $canPair = count($row) === 1 && $this->isShortOption($row[0]);
            if ($canPair) {
                if ($pending === null) {
                    $pending = $row[0];
                } else {
                    $result[] = [$pending, $row[0]];
                    $pending = null;
                }

                continue;
            }

            if ($pending !== null) {
                $result[] = [$pending];
                $pending = null;
            }
            $result[] = $row;
        }

        if ($pending !== null) {
            $result[] = [$pending];
        }

        return $result;
    }

    private function isShortOption(array $button): bool
    {
        $label = trim((string) ($button['text'] ?? ''));
        $label = preg_replace('/[\x{200c}\x{200e}\x{200f}\x{fe0f}]/u', '', $label);
        if ($label === '' || str_contains($label, "\n") || mb_strwidth($label) > 24) {
            return false;
        }

        // Keep full-width all-country choices, back/cancel buttons and section titles.
        if (preg_match('/همه\s*کشور|بازگشت|برگشت|انصراف|منو(?:ی)?\s*اصلی|مرحله\s*بعد/u', $label)) {
            return false;
        }
        if (($button['callback_data'] ?? '') === 'ignore') {
            return false;
        }

        return true;
    }
}
