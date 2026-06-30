<?php

namespace Tests\Feature;

use App\Contracts\AnonymizerContract;
use App\DTOs\AnonymizationResult;
use App\Jobs\AnonymizeEmailJob;
use App\Models\Email;
use App\Models\PipelineLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PipelineLogTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_logs_started_and_succeeded_rows_when_anonymize_succeeds(): void
    {
        $email = Email::factory()->create(['is_anonymized' => false]);

        $this->app->bind(AnonymizerContract::class, fn () => new class implements AnonymizerContract
        {
            public function anonymize(string $text): AnonymizationResult
            {
                return new AnonymizationResult('anonymized:'.$text, []);
            }

            public function deanonymize(string $text, array $mappings): string
            {
                return $text;
            }
        });

        AnonymizeEmailJob::dispatchSync($email->id);

        $logs = PipelineLog::where('email_id', $email->id)->orderBy('id')->get();

        $this->assertCount(2, $logs);
        $this->assertSame('anonymize', $logs[0]->stage);
        $this->assertSame('started', $logs[0]->status);
        $this->assertSame('succeeded', $logs[1]->status);
        $this->assertNotEmpty($logs[0]->payload['config']['analyzer_url']);
        $this->assertSame(0, $logs[1]->payload['outputs']['pii_mapping_count']);
    }

    /** @test */
    public function it_logs_skipped_when_already_anonymized(): void
    {
        $email = Email::factory()->anonymized()->create();

        AnonymizeEmailJob::dispatchSync($email->id);

        $logs = PipelineLog::where('email_id', $email->id)->get();

        $this->assertCount(1, $logs);
        $this->assertSame('skipped', $logs->first()->status);
    }

    /** @test */
    public function it_logs_failed_and_rethrows_when_anonymize_throws(): void
    {
        $email = Email::factory()->create(['is_anonymized' => false]);

        $this->app->bind(AnonymizerContract::class, fn () => new class implements AnonymizerContract
        {
            public function anonymize(string $text): AnonymizationResult
            {
                throw new RuntimeException('presidio down');
            }

            public function deanonymize(string $text, array $mappings): string
            {
                return $text;
            }
        });

        try {
            AnonymizeEmailJob::dispatchSync($email->id);
            $this->fail('Expected the job to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('presidio down', $e->getMessage());
        }

        $logs = PipelineLog::where('email_id', $email->id)->orderBy('id')->get();

        $this->assertCount(2, $logs);
        $this->assertSame('started', $logs[0]->status);
        $this->assertSame('failed', $logs[1]->status);
        $this->assertSame('presidio down', $logs[1]->message);
        $this->assertSame('presidio down', $logs[1]->payload['error']);
    }
}
