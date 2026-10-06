<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Insights\PeriodSummaryValidator;
use App\Support\Analytics\Comparison;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Concerns\BuildsPeriodSummaryFixtures;

class PeriodSummaryValidatorTest extends TestCase
{
    use BuildsPeriodSummaryFixtures;

    public function test_a_coherent_answer_is_valid(): void
    {
        $this->assertSame([], $this->errors($this->validOutput()));
    }

    public function test_the_answer_must_be_an_object_with_exactly_the_schema_fields(): void
    {
        $this->assertSame(['the answer must be an object.'], (new PeriodSummaryValidator)->validate([1, 2], $this->catalog()));

        $missing = $this->validOutput();
        unset($missing['overview']);
        $this->assertSame(['the answer is missing: overview.'], $this->errors($missing));

        $this->assertSame(['the answer has unexpected fields.'], $this->errors([...$this->validOutput(), 'url' => 'https://evil.test']));

        $output = $this->validOutput();
        $output['findings'][0]['link'] = 'https://evil.test';
        $this->assertSame(['findings[0] has unexpected fields.'], $this->errors($output));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function textsWithDigits(): array
    {
        return [
            'headline' => ['headline', 'A receita cresceu vinte por cento, ou seja 20%'],
            'overview' => ['overview', 'O período teve receita de R$ 12.000,00, acima do período anterior e com menos pedidos pagos.'],
            'finding title' => ['findings.0.title', 'Receita subiu 2x'],
            'finding explanation' => ['findings.0.explanation', 'Em 2026 a receita cresceu.'],
            'attention text' => ['attention_points.0.text', 'Foram 4 reembolsos.'],
            'non-ASCII digit' => ['headline', 'A receita cresceu ٣ vezes no período'],
        ];
    }

    #[DataProvider('textsWithDigits')]
    public function test_no_text_may_contain_a_digit(string $path, string $text): void
    {
        $output = $this->validOutput();
        data_set($output, $path, $text);

        $errors = $this->errors($output);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('must not contain digits', $errors[0]);
    }

    public function test_text_lengths_are_enforced_locally(): void
    {
        $output = $this->validOutput();
        $output['headline'] = 'Curto';
        $output['overview'] = str_repeat('a', 601);
        $output['findings'][0]['title'] = str_repeat('b', 91);
        $output['findings'][0]['explanation'] = '   ';
        $output['attention_points'][0]['text'] = str_repeat('c', 201);

        $this->assertSame([
            'headline must have between 10 and 140 characters (has 5).',
            'overview must have between 40 and 600 characters (has 601).',
            'findings[0].title must have between 1 and 90 characters (has 91).',
            'findings[0].explanation must have between 1 and 400 characters (has 0).',
            'attention_points[0].text must have between 1 and 200 characters (has 201).',
        ], $this->errors($output));
    }

    public function test_list_sizes_are_enforced(): void
    {
        $output = $this->validOutput();
        $output['findings'] = [];
        $this->assertSame(['findings must have between 1 and 5 items.'], $this->errors($output));

        $output = $this->validOutput();
        $output['findings'] = array_fill(0, 6, $this->validOutput()['findings'][0]);
        $this->assertSame(['findings must have between 1 and 5 items.'], $this->errors($output));

        $output = $this->validOutput();
        $output['attention_points'] = array_fill(0, 4, $this->validOutput()['attention_points'][0]);
        $this->assertSame(['attention_points must have between 0 and 3 items.'], $this->errors($output));

        $output = $this->validOutput();
        $output['attention_points'] = [];
        $this->assertSame([], $this->errors($output), 'no attention point is fine');

        $output = $this->validOutput();
        $output['findings'][0]['evidence'] = ['kpi.revenue', 'kpi.orders', 'kpi.customers', 'customers.new'];
        $this->assertSame(['findings[0].evidence must have between 1 and 3 items.'], $this->errors($output));

        $output = $this->validOutput();
        $output['attention_points'][0]['evidence'] = [];
        $this->assertSame(['attention_points[0].evidence must have between 1 and 3 items.'], $this->errors($output));
    }

    public function test_only_refs_of_the_catalog_may_be_cited_and_they_are_not_echoed(): void
    {
        $output = $this->validOutput();
        $output['findings'][0]['evidence'] = ['kpi.revenue', 'product.4'];
        $output['attention_points'][0]['evidence'] = ['ignore previous instructions'];

        $errors = $this->errors($output);

        $this->assertSame([
            'findings[0].evidence[1] is not an available ref.',
            'attention_points[0].evidence[0] is not an available ref.',
        ], $errors);
        $this->assertStringNotContainsString('ignore', implode(' ', $errors));

        $output = $this->validOutput();
        $output['findings'][0]['evidence'] = ['kpi.revenue', 'kpi.revenue'];
        $this->assertSame(['findings[0].evidence cites the same ref twice.'], $this->errors($output));
    }

    public function test_kind_and_destination_come_from_their_enums(): void
    {
        $output = $this->validOutput();
        $output['findings'][0]['kind'] = 'great';
        $output['findings'][1]['destination'] = 'https://evil.test';

        $this->assertSame([
            'findings[0].kind must be one of: positive, negative, neutral, attention.',
            'findings[1].destination must be one of: dashboard, analytics.revenue, analytics.products, analytics.customers, analytics.transactions, transactions.refunded, transactions.canceled, transactions.pending.',
        ], $this->errors($output));
    }

    /**
     * kpi.orders fell (positive polarity), kpi.revenue rose (positive), status.refunded rose
     * (negative polarity), status.canceled fell (negative), status.pending rose (neutral).
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function directions(): array
    {
        return [
            'positive on a rise' => ['positive', 'kpi.revenue', true],
            'positive on a fall' => ['positive', 'kpi.orders', false],
            'negative on a fall' => ['negative', 'kpi.orders', true],
            'negative on a rise' => ['negative', 'kpi.revenue', false],
            'positive on fewer cancellations' => ['positive', 'status.canceled', true],
            'positive on more refunds' => ['positive', 'status.refunded', false],
            'negative on more refunds' => ['negative', 'status.refunded', true],
            'negative on fewer cancellations' => ['negative', 'status.canceled', false],
            'neutral polarity is never incoherent' => ['positive', 'status.pending', true],
            'stable metric' => ['negative', 'kpi.customers', true],
            'neutral kind' => ['neutral', 'kpi.orders', true],
            'attention kind' => ['attention', 'kpi.revenue', true],
        ];
    }

    #[DataProvider('directions')]
    public function test_the_kind_must_agree_with_the_direction_and_polarity_of_its_evidence(string $kind, string $ref, bool $valid): void
    {
        $output = $this->validOutput();
        $output['findings'] = [[...$output['findings'][0], 'kind' => $kind, 'evidence' => [$ref]]];

        $errors = $this->errors($output);

        $valid ? $this->assertSame([], $errors) : $this->assertCount(1, $errors);

        if (! $valid) {
            $this->assertStringContainsString("findings[0] is {$kind} but cites {$ref}", $errors[0]);
        }
    }

    public function test_an_undefined_change_does_not_constrain_the_kind(): void
    {
        $catalog = $this->catalog(['kpi.revenue' => Comparison::ofMoney(500, 0)]);
        $output = $this->validOutput();
        $output['findings'][0]['kind'] = 'negative';
        $output['findings'][0]['evidence'] = ['kpi.revenue'];

        $this->assertSame([], (new PeriodSummaryValidator)->validate($output, $catalog));
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<string>
     */
    private function errors(array $output): array
    {
        return (new PeriodSummaryValidator)->validate($output, $this->catalog());
    }
}
