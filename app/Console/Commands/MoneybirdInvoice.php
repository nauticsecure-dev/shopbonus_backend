<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Models\FundingInvoice;
use App\Models\FundingInvoiceExport;
use App\Models\User;
use App\Services\Funding\MoneybirdInvoices;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MoneybirdInvoice extends Command
{
    protected $signature = 'shopbonus:moneybird-invoice {action : prepare|create|send|reconcile|bind} {id : Statement public ID for prepare, export public ID otherwise} {--actor= : Active Super Admin public user ID} {--remote-id= : Existing provider invoice ID for bind}';

    protected $description = 'Explicitly prepare/export/reconcile a frozen funding invoice with Moneybird';

    public function handle(MoneybirdInvoices $invoices): int
    {
        $action = (string) $this->argument('action');
        $id = strtolower((string) $this->argument('id'));
        $actorId = strtolower((string) $this->option('actor'));
        if (! in_array($action, ['prepare', 'create', 'send', 'reconcile', 'bind'], true) || ! Str::isUlid($id) || ! Str::isUlid($actorId) || ($action === 'bind' && ! preg_match('/\A[1-9][0-9]{0,31}\z/D', (string) $this->option('remote-id')))) {
            $this->error('Provide a supported action, public record ID and --actor public ID.');

            return self::FAILURE;
        }
        $actor = User::where('public_id', $actorId)->first();
        $record = $action === 'prepare' ? FundingInvoice::where('public_id', $id)->first() : FundingInvoiceExport::where('public_id', $id)->first();
        if ($actor === null || $record === null) {
            $this->error('Actor or record not found.');

            return self::FAILURE;
        }
        try {
            $result = $record instanceof FundingInvoice ? $invoices->prepare($record, $actor) : match ($action) {
                'create' => $invoices->create($record, $actor), 'send' => $invoices->send($record, $actor),
                'reconcile' => $invoices->reconcile($record, $actor), 'bind' => $invoices->bind($record, (string) $this->option('remote-id'), $actor),
            };
            $this->line(json_encode(['id' => $result->public_id, 'phase' => $result->phase, 'remote_state' => $result->remote_state,
                'unpaid_minor' => $result->unpaid_minor, 'delivery_request_acknowledged' => $result->delivery_acknowledged_at !== null,
                'recipient_delivery' => 'unverified'], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (ApiException $e) {
            $this->error($e->errorCode.': '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
