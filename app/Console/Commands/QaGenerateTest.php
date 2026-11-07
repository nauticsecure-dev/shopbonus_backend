<?php

namespace App\Console\Commands;

use App\Models\QaEndpoint;
use App\Models\QaTestCase;
use App\Models\User;
use App\Services\Qa\QaTestGenerator;
use Illuminate\Console\Command;
use Throwable;

class QaGenerateTest extends Command
{
    protected $signature = 'shopbonus:qa-generate-test {proposal : Existing AI proposal public ID} {endpoint : Discovered endpoint public ID} {--actor= : Current QA or Super Admin user numeric ID}';

    protected $description = 'Generate a review-required protected GET denial regression from a discovered endpoint and linked AI proposal';

    public function handle(QaTestGenerator $generator): int
    {
        try {
            $proposal = QaTestCase::where('public_id', $this->argument('proposal'))->firstOrFail();
            $endpoint = QaEndpoint::where('public_id', $this->argument('endpoint'))->firstOrFail();
            $actor = User::findOrFail((int) $this->option('actor'));
            $this->line(json_encode($generator->generate($proposal, $endpoint, $actor), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $error) {
            report($error);
            $this->error('Test generation failed. Verify actor, proposal, discovered endpoint and existing coverage.');

            return self::FAILURE;
        }
    }
}
