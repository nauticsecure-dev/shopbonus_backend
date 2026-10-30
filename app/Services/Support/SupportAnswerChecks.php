<?php

namespace App\Services\Support;

/**
 * Named pre-publish checks and the single targeted retry diagnostic packet.
 * Laravel enforces these independently of prompts and OpenAI output.
 */
final class SupportAnswerChecks
{
    public const CODES = ['AUTH', 'SOURCE', 'FRESHNESS', 'COMPLETENESS', 'CONSISTENCY', 'CLAIMS', 'POLICY', 'ACTION'];

    /**
     * @param  list<string>  $passed
     * @param  list<string>  $failed
     * @return array{passed: list<string>, failed: list<string>}
     */
    public static function report(array $passed, array $failed = []): array
    {
        $passed = array_values(array_intersect(self::CODES, array_unique($passed)));
        $failed = array_values(array_intersect(self::CODES, array_unique($failed)));

        return ['passed' => $passed, 'failed' => $failed];
    }

    /**
     * Packet for exactly one additional authorized tool round.
     *
     * @param  array<string, mixed>  $knownFacts
     * @param  list<string>  $missingFacts
     * @param  list<string>  $allowedNextTools
     * @return array<string, mixed>
     */
    public static function diagnostic(
        string $question,
        string $failureReason,
        array $knownFacts,
        array $missingFacts,
        array $allowedNextTools,
        int $maxAdditionalToolRounds = 1,
    ): array {
        return [
            'original_question' => $question,
            'failure_reason' => $failureReason,
            'known_facts' => $knownFacts,
            'missing_facts' => array_values($missingFacts),
            'allowed_next_tools' => array_values(array_intersect($allowedNextTools, array_keys(SupportTools::ARGUMENTS))),
            'max_additional_tool_rounds' => max(0, min(1, $maxAdditionalToolRounds)),
        ];
    }
}
