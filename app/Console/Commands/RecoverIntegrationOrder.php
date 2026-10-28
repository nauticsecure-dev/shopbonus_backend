<?php

namespace App\Console\Commands;

use App\Integrations\OrderRecovery;
use App\Jobs\RecoverProviderOrder;
use App\Models\IntegrationOrderRecovery;
use App\Models\WebshopConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RecoverIntegrationOrder extends Command
{
    protected $signature = 'shopbonus:recover-integration-order {connection?} {order?} {--request= : Stable UUID for this request} {--drain : Drain existing unfinished requests only} {--limit=25}';

    protected $description = 'Operator-only authenticated single-order recovery through the canonical ingestion pipeline';

    public function handle(OrderRecovery $recovery): int
    {
        if ($this->option('drain')) {
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > 100) {
                $this->error('Limit must be between1and100.');

                return self::INVALID;
            }
            $rows = IntegrationOrderRecovery::where(function ($query) {
                $query->whereIn('status', ['pending', 'staged'])->orWhere(function ($q) {
                    $q->where('status', 'reading')->where('leased_until', '<=', now());
                });
            })->orderBy('id')->limit($limit)->get();
            foreach ($rows as $row) {
                RecoverProviderOrder::dispatch($row->id);
            }
            $this->info('Dispatched '.$rows->count().' unfinished recovery requests.');

            return self::SUCCESS;
        }
        $id = $this->argument('connection');
        $order = $this->argument('order');
        $key = $this->option('request') ?: (string) Str::uuid();
        if (! is_string($id) || ! Str::isUlid($id) || ! is_string($order) || ! preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $order)
            || ! Str::isUuid($key)) {
            $this->error('Provide a connection ULID, native order ID and optional request UUID.');

            return self::INVALID;
        }
        $c = WebshopConnection::where('public_id', strtolower($id))->first();
        if ($c === null) {
            $this->error('Connection not found.');

            return self::FAILURE;
        }
        try {
            $r = $recovery->request($c, $order, $key);
        } catch (\InvalidArgumentException) {
            $this->error('Invalid native order ID.');

            return self::INVALID;
        }
        $this->line(json_encode(['recovery' => $r->public_id, 'request' => $key, 'status' => $r->fresh()?->status], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
