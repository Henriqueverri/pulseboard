<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Fake\ScriptedLlmClient;
use App\Services\Ai\Insights\PeriodSummarySchema;
use App\Services\Ai\Insights\PeriodSummaryValidator;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Concerns\BuildsPeriodSummaryFixtures;

class PeriodSummarySchemaTest extends TestCase
{
    use BuildsPeriodSummaryFixtures;

    public function test_evidence_is_an_enum_of_the_refs_present_in_the_catalog(): void
    {
        $catalog = $this->catalog(products: 2);
        $schema = PeriodSummarySchema::for($catalog)->schema;

        $findingRefs = $schema['properties']['findings']['items']['properties']['evidence']['items']['enum'];
        $attentionRefs = $schema['properties']['attention_points']['items']['properties']['evidence']['items']['enum'];

        $this->assertSame($catalog->refs(), $findingRefs);
        $this->assertSame($catalog->refs(), $attentionRefs);
        $this->assertContains('product.2', $findingRefs);
        $this->assertNotContains('product.3', $findingRefs);
    }

    public function test_the_schema_follows_the_strict_mode_subset(): void
    {
        $output = PeriodSummarySchema::for($this->catalog());

        $this->assertSame('period_summary_v1', $output->name);
        $this->assertMatchesRegularExpression('/\A[a-zA-Z0-9_-]{1,64}\z/', $output->name);
        $this->assertStrictObjects($output->schema, 'root');
    }

    public function test_destinations_and_kinds_are_closed_enums(): void
    {
        $item = PeriodSummarySchema::for($this->catalog())->schema['properties']['findings']['items']['properties'];

        $this->assertSame(PeriodSummarySchema::KINDS, $item['kind']['enum']);
        $this->assertSame(PeriodSummarySchema::DESTINATIONS, $item['destination']['enum']);
        $this->assertSame([], preg_grep('#[/:]#', PeriodSummarySchema::DESTINATIONS), 'destinations are screen names, never URLs');
    }

    public function test_the_scripted_provider_default_answer_passes_validation(): void
    {
        $catalog = $this->catalog();
        $answer = ScriptedLlmClient::exampleOf(PeriodSummarySchema::for($catalog)->schema);

        $this->assertSame([], (new PeriodSummaryValidator)->validate($answer, $catalog));
    }

    /**
     * Every object lists all of its properties as required and forbids additional ones.
     *
     * @param  array<string, mixed>  $schema
     */
    private function assertStrictObjects(array $schema, string $path): void
    {
        if (($schema['type'] ?? null) === 'object') {
            $this->assertFalse($schema['additionalProperties'], "{$path} additionalProperties");
            $this->assertSame(array_keys($schema['properties']), $schema['required'], "{$path} required");

            foreach ($schema['properties'] as $name => $property) {
                $this->assertStrictObjects($property, "{$path}.{$name}");
            }
        }

        if (($schema['type'] ?? null) === 'array') {
            $this->assertStrictObjects($schema['items'], "{$path}[]");
        }
    }
}
