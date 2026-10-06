<?php

namespace Tests\Unit\Ai;

use App\Data\Ai\LlmMessage;
use App\Data\Ai\LlmRequest;
use App\Data\Ai\OutputSchema;
use App\Data\Ai\ToolCall;
use App\Data\Ai\ToolDefinition;
use App\Data\Ai\ToolExchange;
use App\Exceptions\AiException;
use App\Services\Ai\Fake\ScriptedLlmClient;
use LogicException;
use PHPUnit\Framework\TestCase;

class ScriptedLlmClientTest extends TestCase
{
    public function test_queued_steps_are_answered_in_order_and_requests_are_recorded(): void
    {
        $client = (new ScriptedLlmClient)
            ->pushToolCalls([['get_period_kpis', ['from' => null, 'to' => null]]])
            ->pushOutput(['status' => 'answered']);

        $first = $client->respond($this->request());
        $second = $client->respond($this->request());

        $this->assertSame('get_period_kpis', $first->toolCalls[0]->name);
        $this->assertSame('{"from":null,"to":null}', $first->toolCalls[0]->arguments);
        $this->assertSame(['status' => 'answered'], $second->json());
        $this->assertCount(2, $client->requests());
        $this->assertSame(0, $client->pendingSteps());
    }

    public function test_failures_refusals_and_raw_output_can_be_scripted(): void
    {
        $client = (new ScriptedLlmClient)
            ->pushFailure(AiException::timeout())
            ->pushRefusal()
            ->pushOutput('not json')
            ->pushIncomplete();

        try {
            $client->respond($this->request());
            $this->fail('Expected an AiException.');
        } catch (AiException $exception) {
            $this->assertSame(AiException::TIMEOUT, $exception->errorCode);
        }

        $this->assertTrue($client->respond($this->request())->isRefusal());
        $this->assertNull($client->respond($this->request())->json());
        $this->assertTrue($client->respond($this->request())->isIncomplete());
    }

    public function test_by_default_it_calls_the_first_tool_once_then_answers_with_the_schema(): void
    {
        $client = new ScriptedLlmClient;
        $tool = new ToolDefinition('get_period_kpis', 'KPIs', [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => ['string', 'null']],
                'to' => ['type' => ['string', 'null']],
            ],
            'required' => ['from', 'to'],
            'additionalProperties' => false,
        ]);
        $request = new LlmRequest('Rules', [LlmMessage::user('Question')], $this->schema(), [$tool]);

        $first = $client->respond($request);

        $this->assertSame('get_period_kpis', $first->toolCalls[0]->name);
        $this->assertSame(['from' => null, 'to' => null], json_decode($first->toolCalls[0]->arguments, true));

        $second = $client->respond($request->withAppendedInput([new ToolExchange($first->toolCalls[0], '{}')]));

        $this->assertFalse($second->hasToolCalls());
        $this->assertSame('neutral', $second->json()['findings'][0]['kind']);
    }

    public function test_the_schema_example_is_deterministic_neutral_and_free_of_digits(): void
    {
        $example = ScriptedLlmClient::exampleOf($this->schema()->schema);

        $this->assertSame($example, ScriptedLlmClient::exampleOf($this->schema()->schema));
        $this->assertCount(1, $example['findings']);
        $this->assertSame('neutral', $example['findings'][0]['kind']);
        $this->assertSame(['kpi.revenue'], $example['findings'][0]['evidence']);
        $this->assertSame([], $example['attention_points']);
        $this->assertNull($example['note']);
        $this->assertDoesNotMatchRegularExpression('/\d/', json_encode($example, JSON_UNESCAPED_UNICODE));
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->expectException(LogicException::class);

        new ScriptedLlmClient(production: true);
    }

    public function test_scripted_tool_calls_get_unique_ids(): void
    {
        $client = (new ScriptedLlmClient)->pushToolCalls([['a', []], ['b', '{"raw":true}']]);

        $calls = $client->respond($this->request())->toolCalls;

        $this->assertContainsOnlyInstancesOf(ToolCall::class, $calls);
        $this->assertNotSame($calls[0]->id, $calls[1]->id);
        $this->assertSame('{}', $calls[0]->arguments);
        $this->assertSame('{"raw":true}', $calls[1]->arguments);
    }

    private function request(): LlmRequest
    {
        return new LlmRequest('Rules', [LlmMessage::user('Context')]);
    }

    private function schema(): OutputSchema
    {
        return new OutputSchema('test', [
            'type' => 'object',
            'properties' => [
                'headline' => ['type' => 'string'],
                'findings' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 5,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'kind' => ['type' => 'string', 'enum' => ['positive', 'negative', 'neutral', 'attention']],
                            'evidence' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string', 'enum' => ['kpi.revenue', 'kpi.orders']]],
                        ],
                        'required' => ['kind', 'evidence'],
                        'additionalProperties' => false,
                    ],
                ],
                'attention_points' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string']],
                'note' => ['type' => ['string', 'null']],
            ],
            'required' => ['headline', 'findings', 'attention_points', 'note'],
            'additionalProperties' => false,
        ]);
    }
}
