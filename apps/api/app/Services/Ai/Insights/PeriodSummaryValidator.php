<?php

namespace App\Services\Ai\Insights;

use App\Data\Ai\Evidence;

/**
 * Validates a model answer locally, never trusting the provider's strict mode
 * alone: first the structure of PeriodSummarySchema (with the text lengths the
 * schema cannot express), then the semantic rules:
 *
 * - every cited ref exists in this period's catalog;
 * - no digit in any text, since numbers are only shown from evidence;
 * - a positive finding never cites a metric that moved the wrong way (and the
 *   reverse for negative findings), using the metric's polarity.
 *
 * Errors describe the problem without echoing the answer, and are sent back to
 * the model in the repair attempt.
 */
final class PeriodSummaryValidator
{
    private const DIGIT = '/\p{Nd}/u';

    /** @var list<string> */
    private array $errors = [];

    /**
     * @param  array<string, mixed>  $output
     * @return list<string> empty when the answer is valid
     */
    public function validate(array $output, EvidenceCatalog $catalog): array
    {
        $this->errors = [];

        if (! $this->keys($output, ['headline', 'overview', 'findings', 'attention_points'], 'the answer')) {
            return $this->errors;
        }

        $this->text($output['headline'], 'headline', PeriodSummarySchema::HEADLINE_MIN, PeriodSummarySchema::HEADLINE_MAX);
        $this->text($output['overview'], 'overview', PeriodSummarySchema::OVERVIEW_MIN, PeriodSummarySchema::OVERVIEW_MAX);

        if ($this->list($output['findings'], 'findings', PeriodSummarySchema::FINDINGS_MIN, PeriodSummarySchema::FINDINGS_MAX)) {
            foreach ($output['findings'] as $index => $finding) {
                $this->finding($finding, "findings[{$index}]", $catalog);
            }
        }

        if ($this->list($output['attention_points'], 'attention_points', 0, PeriodSummarySchema::ATTENTION_POINTS_MAX)) {
            foreach ($output['attention_points'] as $index => $point) {
                $path = "attention_points[{$index}]";

                if ($this->keys($point, ['text', 'evidence'], $path)) {
                    $this->text($point['text'], "{$path}.text", 1, PeriodSummarySchema::ATTENTION_TEXT_MAX);
                    $this->evidence($point['evidence'], "{$path}.evidence", $catalog);
                }
            }
        }

        return $this->errors;
    }

    private function finding(mixed $finding, string $path, EvidenceCatalog $catalog): void
    {
        if (! $this->keys($finding, ['kind', 'title', 'explanation', 'evidence', 'destination'], $path)) {
            return;
        }

        $kindIsValid = $this->oneOf($finding['kind'], PeriodSummarySchema::KINDS, "{$path}.kind");
        $this->text($finding['title'], "{$path}.title", 1, PeriodSummarySchema::TITLE_MAX);
        $this->text($finding['explanation'], "{$path}.explanation", 1, PeriodSummarySchema::EXPLANATION_MAX);
        $this->oneOf($finding['destination'], PeriodSummarySchema::DESTINATIONS, "{$path}.destination");

        if (! $this->evidence($finding['evidence'], "{$path}.evidence", $catalog) || ! $kindIsValid) {
            return;
        }

        foreach ($finding['evidence'] as $ref) {
            $this->direction($finding['kind'], $catalog->get($ref), $path);
        }
    }

    /**
     * A rise of a positive-polarity metric (or a fall of a negative one) is good news.
     */
    private function direction(string $kind, Evidence $evidence, string $path): void
    {
        $change = $evidence->comparison->change;

        if (! in_array($kind, [PeriodSummarySchema::KIND_POSITIVE, PeriodSummarySchema::KIND_NEGATIVE], true)
            || $change === null || $change === 0.0 || $evidence->polarity === Evidence::POLARITY_NEUTRAL) {
            return;
        }

        $improved = $evidence->polarity === Evidence::POLARITY_POSITIVE ? $change > 0 : $change < 0;

        if ($improved !== ($kind === PeriodSummarySchema::KIND_POSITIVE)) {
            $direction = $change > 0 ? 'rose' : 'fell';
            $this->errors[] = "{$path} is {$kind} but cites {$evidence->ref}, which {$direction} and has {$evidence->polarity} polarity.";
        }
    }

    /**
     * @param  list<string>  $expected
     */
    private function keys(mixed $value, array $expected, string $path): bool
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->errors[] = "{$path} must be an object.";

            return false;
        }

        $missing = array_diff($expected, array_keys($value));
        $extra = array_diff(array_keys($value), $expected);

        if ($missing !== []) {
            $this->errors[] = "{$path} is missing: ".implode(', ', $missing).'.';
        }

        if ($extra !== []) {
            $this->errors[] = "{$path} has unexpected fields.";
        }

        return $missing === [] && $extra === [];
    }

    private function text(mixed $value, string $path, int $min, int $max): void
    {
        if (! is_string($value)) {
            $this->errors[] = "{$path} must be a string.";

            return;
        }

        $length = mb_strlen(trim($value));

        if ($length < $min || $length > $max) {
            $this->errors[] = "{$path} must have between {$min} and {$max} characters (has {$length}).";
        }

        if (preg_match(self::DIGIT, $value) === 1) {
            $this->errors[] = "{$path} must not contain digits; cite the metric in evidence instead.";
        }
    }

    private function list(mixed $value, string $path, int $min, int $max): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->errors[] = "{$path} must be a list.";

            return false;
        }

        if (count($value) < $min || count($value) > $max) {
            $this->errors[] = "{$path} must have between {$min} and {$max} items.";

            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function oneOf(mixed $value, array $allowed, string $path): bool
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            $this->errors[] = "{$path} must be one of: ".implode(', ', $allowed).'.';

            return false;
        }

        return true;
    }

    private function evidence(mixed $refs, string $path, EvidenceCatalog $catalog): bool
    {
        if (! $this->list($refs, $path, PeriodSummarySchema::EVIDENCE_MIN, PeriodSummarySchema::EVIDENCE_MAX)) {
            return false;
        }

        $valid = true;

        foreach ($refs as $index => $ref) {
            if (! is_string($ref) || ! $catalog->has($ref)) {
                $this->errors[] = "{$path}[{$index}] is not an available ref.";
                $valid = false;
            }
        }

        if ($valid && count(array_unique($refs)) !== count($refs)) {
            $this->errors[] = "{$path} cites the same ref twice.";
            $valid = false;
        }

        return $valid;
    }
}
