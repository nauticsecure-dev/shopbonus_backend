<?php

namespace App\Services\Vouchers;

use App\Enums\AuditCategory;
use App\Enums\OfferStatus;
use App\Exceptions\ApiException;
use App\Jobs\CommitCodeBatch;
use App\Models\Offer;
use App\Models\VoucherCode;
use App\Models\VoucherCodeBatch;
use App\Models\VoucherEvent;
use App\Models\WebshopMembership;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Paste/CSV code import with preview → commit (Trello #7 §6).
 *
 * Preview validates and reports everything (format, duplicates inside the
 * upload, duplicates already in this webshop) without creating codes.
 * Commit inserts all still-valid codes in one transaction; codes that became
 * duplicates since the preview are skipped and reported. Importing codes
 * never charges anything and never proves the webshop accepts them
 * (`platform_validation` stays `merchant_verification_required`).
 */
class CodeImporter
{
    public const FORMAT = '/^[A-Za-z0-9_-]{4,64}$/';

    public const MAX_CODES = 50_000;

    public const SYNC_COMMIT_LIMIT = 2_000;

    public const PREVIEW_MINUTES = 60;

    private const MAX_REJECTIONS_SHOWN = 200;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return list<string>
     */
    public function linesFromFile(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (in_array($extension, ['xlsx', 'xlsm'], true)) {
            return app(SpreadsheetCodeReader::class)->lines($file);
        }

        if (in_array($extension, ['xls', 'ods'], true)) {
            throw new ApiException('Legacy .xls/.ods are not supported. Upload .xlsx or CSV.', 415, 'unsupported_media_type');
        }

        $handle = fopen($file->getRealPath(), 'r');
        $lines = [];
        $column = 0;
        $first = true;

        while ($handle !== false && ($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $row = array_map(fn ($v) => trim((string) $v), $row);

            if ($first) {
                $first = false;
                $header = array_map('strtolower', $row);
                if (in_array('code', $header, true)) {
                    $column = (int) array_search('code', $header, true);

                    continue;
                }
            }

            $lines[] = (string) ($row[$column] ?? '');
        }

        if ($handle !== false) {
            fclose($handle);
        }

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     */
    public function preview(WebshopMembership $actor, Offer $offer, array $lines, string $source): VoucherCodeBatch
    {
        if (in_array($offer->status, [OfferStatus::Ended, OfferStatus::Archived], true)) {
            throw ApiException::conflict('Codes cannot be added to an ended or archived offer.', $offer->status === OfferStatus::Archived ? 'offer_archived' : 'offer_ended');
        }

        $lines = array_values(array_filter(array_map('trim', $lines), fn (string $l) => $l !== ''));

        if (count($lines) > self::MAX_CODES) {
            throw new ApiException('At most '.self::MAX_CODES.' codes per import.', 422, 'too_many_codes');
        }

        $valid = [];
        $seen = [];
        $rejections = [];
        $totals = ['submitted' => count($lines), 'valid' => 0, 'invalid' => 0, 'duplicate_in_upload' => 0, 'duplicate_existing' => 0];

        foreach ($lines as $i => $code) {
            $reason = null;
            $hash = VoucherCode::hashFor($offer->webshop_id, $code);

            if (preg_match(self::FORMAT, $code) !== 1) {
                $reason = 'invalid_format';
                $totals['invalid']++;
            } elseif (isset($seen[$hash])) {
                $reason = 'duplicate_in_upload';
                $totals['duplicate_in_upload']++;
            } else {
                $seen[$hash] = $i;
                $valid[$hash] = $code;
            }

            if ($reason !== null && count($rejections) < self::MAX_REJECTIONS_SHOWN) {
                $rejections[] = ['line' => $i + 1, 'code' => self::mask($code), 'reason' => $reason];
            }
        }

        foreach (array_chunk(array_keys($valid), 1000) as $chunk) {
            foreach (VoucherCode::where('webshop_id', $offer->webshop_id)->whereIn('code_hash', $chunk)->pluck('code_hash') as $existing) {
                $totals['duplicate_existing']++;
                if (count($rejections) < self::MAX_REJECTIONS_SHOWN) {
                    $rejections[] = ['line' => $seen[$existing] + 1, 'code' => self::mask($valid[$existing]), 'reason' => 'duplicate_existing'];
                }
                unset($valid[$existing]);
            }
        }

        $reusable = $offer->currentVersion?->code_mode === 'reusable';
        if ($reusable && (count($valid) > 1 || VoucherCode::where('offer_id', $offer->id)->exists())) {
            throw new ApiException('A reusable-code offer has exactly one shared code.', 422, 'reusable_offer_single_code');
        }

        $totals['valid'] = count($valid);
        usort($rejections, fn ($a, $b) => $a['line'] <=> $b['line']);

        $batch = VoucherCodeBatch::create([
            'webshop_id' => $offer->webshop_id,
            'offer_id' => $offer->id,
            'source' => $source,
            'status' => 'preview',
            'totals' => $totals,
            'rejections' => $rejections,
            'payload' => array_values($valid),
            'expires_at' => now()->addMinutes(self::PREVIEW_MINUTES),
            'created_by' => $actor->user_id,
        ]);

        $this->audit->record('codes.import_previewed', category: AuditCategory::Voucher, resource: $batch,
            webshopId: $offer->webshop_id, metadata: ['totals' => $totals, 'source' => $source]);

        return $batch;
    }

    public function commit(WebshopMembership $actor, VoucherCodeBatch $batch): VoucherCodeBatch
    {
        $claimed = VoucherCodeBatch::whereKey($batch->id)->where('status', 'preview')->where('expires_at', '>', now())
            ->update(['status' => 'committing', 'committed_by' => $actor->user_id, 'updated_at' => now()]);

        if ($claimed !== 1) {
            throw ApiException::conflict('This import preview was already committed, discarded or has expired.', 'batch_not_committable');
        }

        $batch->refresh();

        if (count($batch->payload ?? []) > self::SYNC_COMMIT_LIMIT) {
            CommitCodeBatch::dispatch($batch->id);

            return $batch;
        }

        return $this->insert($batch);
    }

    /**
     * All-or-nothing insert of the batch's codes (used directly and by the queued job).
     */
    public function insert(VoucherCodeBatch $batch): VoucherCodeBatch
    {
        return DB::transaction(function () use ($batch) {
            $batch = VoucherCodeBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status !== 'committing') {
                return $batch;
            }

            $offer = Offer::with('currentVersion')->findOrFail($batch->offer_id);
            $reusable = $offer->currentVersion?->code_mode === 'reusable';
            $codes = $batch->payload ?? [];
            $byHash = [];
            foreach ($codes as $code) {
                $byHash[VoucherCode::hashFor($batch->webshop_id, $code)] = $code;
            }

            // Codes added elsewhere since the preview are skipped, not duplicated.
            $late = 0;
            foreach (array_chunk(array_keys($byHash), 1000) as $chunk) {
                foreach (VoucherCode::where('webshop_id', $batch->webshop_id)->whereIn('code_hash', $chunk)->pluck('code_hash') as $existing) {
                    unset($byHash[$existing]);
                    $late++;
                }
            }

            $now = now();
            foreach (array_chunk($byHash, 500, true) as $chunk) {
                DB::table('voucher_codes')->insert(array_map(fn (string $code, string $hash) => [
                    'public_id' => strtolower((string) Str::ulid()), // same form as HasUlids
                    'webshop_id' => $batch->webshop_id,
                    'offer_id' => $batch->offer_id,
                    'batch_id' => $batch->id,
                    'code' => Crypt::encryptString($code),
                    'code_hash' => $hash,
                    'code_last4' => substr($code, -4),
                    'status' => 'available',
                    'platform_validation' => 'merchant_verification_required',
                    'reusable' => $reusable,
                    'uses_count' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk, array_keys($chunk)));
            }

            $totals = [...$batch->totals, 'imported' => count($byHash), 'duplicate_at_commit' => $late];
            $batch->forceFill(['status' => 'committed', 'committed_at' => $now, 'totals' => $totals, 'payload' => null])->save();

            VoucherEvent::create(['webshop_id' => $batch->webshop_id, 'type' => 'codes_imported', 'actor_user_id' => $batch->committed_by,
                'summary' => ['batch' => $batch->public_id, 'imported' => count($byHash), 'duplicate_at_commit' => $late]]);
            $this->audit->recordSafely('codes.import_committed', category: AuditCategory::Voucher, resource: $batch,
                webshopId: $batch->webshop_id, metadata: ['totals' => $totals], actorUserId: $batch->committed_by);

            return $batch;
        });
    }

    public static function mask(string $code): string
    {
        $len = mb_strlen($code);

        return $len <= 4 ? str_repeat('*', $len) : str_repeat('*', min($len - 4, 8)).mb_substr($code, -4);
    }
}
