<?php

namespace App\Services\Support;

/** Server-owned wording only. Never truncate verified facts or financial safeguards. */
final class SupportPresentation
{
    /** @param array<string, mixed> $result
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    public function apply(array $result, array $policy, string $locale): array
    {
        $policy += SupportPolicy::defaults();
        if (! is_string($result['answer'] ?? null)) {
            return $result;
        }
        $nl = $locale === 'nl';
        $text = $result['answer'];
        if (in_array($result['status'], ['VERIFIED', 'PARTIALLY_VERIFIED'], true)) {
            $prefix = match ($policy['tone']) {
                'warm' => $nl ? 'Ik help u graag. ' : 'Happy to help. ',
                'friendly_professional' => $nl ? 'Dit kon ik controleren. ' : 'Here is what I could verify. ',
                default => '',
            };
            if ($policy['empathy'] === 'high') {
                $prefix .= $nl ? 'Ik begrijp dat dit belangrijk voor u is. ' : 'I understand this is important to you. ';
            } elseif ($policy['empathy'] === 'balanced') {
                $prefix .= $nl ? 'Ik kan dit voor u verduidelijken. ' : 'I can help clarify this. ';
            }
            $text = $prefix.$text;
            if ($policy['answer_detail'] === 'detailed' && $this->words($text) < $policy['target_answer_words']) {
                $text .= $nl ? ' Deze gegevens komen uit de bronnen die voor dit verzoek zijn gecontroleerd.' : ' These details reflect the sources checked for this request.';
            }
        }
        if ($this->words($text) > $policy['max_answer_words']) {
            $result['status'] = 'ESCALATED';
            $text = $nl ? 'Voor een volledig antwoord is een beoordeling door ondersteuning aangevraagd.' : 'A human support review has been requested for a complete answer.';
            $result['action'] = null;
            $result['evidence_ids'] = [];
            $result['checks'] = SupportAnswerChecks::report([], ['POLICY']);
            $result['presentation_reason'] = 'answer_word_limit';
        }
        $result['answer'] = $text;
        $result['presentation'] = ['tone' => $policy['tone'], 'empathy' => $policy['empathy'], 'detail' => $policy['answer_detail'],
            'target_words' => min($policy['target_answer_words'], $policy['max_answer_words']), 'target_is_soft' => true,
            'max_words' => $policy['max_answer_words'], 'word_count' => $this->words($text)];

        return $result;
    }

    private function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
