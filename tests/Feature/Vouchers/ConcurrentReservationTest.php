<?php

namespace Tests\Feature\Vouchers;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherOrigin;
use App\Exceptions\ApiException;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\VoucherInventory;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;
use Throwable;

/**
 * Trello #7 E2E 5: simultaneous requests for the last codes. Real OS
 * processes (pcntl_fork), each with its own MySQL connection, race to
 * reserve; rows are committed (truncation, not a wrapping transaction) so
 * every process sees the same inventory. Runs on MySQL/PostgreSQL only:
 * SQLite in-memory databases are per-process.
 */
class ConcurrentReservationTest extends TestCase
{
    use CreatesActiveWebshops, DatabaseTruncation;

    /**
     * DatabaseTruncation cleans up before each test only; these tests commit
     * rows, so clean up afterwards too or later transactional tests see them.
     */
    protected function tearDown(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::reconnect();
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            foreach (DB::select('SHOW TABLES') as $row) {
                $table = array_values((array) $row)[0];
                if ($table !== 'migrations') {
                    DB::table($table)->truncate();
                }
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        parent::tearDown();
    }

    private function skipUnlessRealDatabase(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->markTestSkipped('Needs MySQL/PostgreSQL (run with DB_CONNECTION=mysql); SQLite memory DBs are per process.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Needs the pcntl extension.');
        }
    }

    private function offerWithCodes(int $codes): Offer
    {
        $owner = User::factory()->create();
        $shop = $this->activeWebshop($owner);
        $membership = $shop->memberships()->sole()->setRelation('webshop', $shop);
        $offers = app(OfferService::class);
        $offer = $offers->transition($offers->create($membership, OfferAcquisition::MerchantReward, [
            'name' => 'Race', 'discount_type' => 'fixed', 'discount_value' => 1000, 'redemption_validity_days' => 30,
        ]), OfferStatus::Active);

        for ($i = 1; $i <= $codes; $i++) {
            VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => "RACE-{$i}",
                'code_hash' => VoucherCode::hashFor($shop->id, "RACE-{$i}"), 'code_last4' => substr("RACE-{$i}", -4)]);
        }

        return $offer;
    }

    /**
     * Forks $workers processes that all call reserve() at the same instant.
     *
     * @return list<string> one result per worker: "ok:<code id>" or "err:<code>"
     */
    private function race(Offer $offer, int $workers): array
    {
        $dir = sys_get_temp_dir().'/shopbonus-race-'.uniqid();
        mkdir($dir);
        $startAt = microtime(true) + 0.5;
        DB::disconnect(); // children must not share the parent's PDO socket

        $pids = [];
        for ($w = 0; $w < $workers; $w++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    DB::reconnect();
                    usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));
                    $voucher = app(VoucherInventory::class)->reserve(Offer::findOrFail($offer->id), VoucherOrigin::MerchantReward, "race-worker-{$w}");
                    $result = 'ok:'.$voucher->voucher_code_id;
                } catch (ApiException $e) {
                    $result = 'err:'.$e->errorCode;
                } catch (Throwable $e) {
                    $result = 'crash:'.$e::class.':'.$e->getMessage();
                }
                file_put_contents("{$dir}/{$w}", $result);
                posix_kill(posix_getpid(), SIGKILL); // skip PHPUnit shutdown in the child
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        $results = array_map(fn ($w) => (string) @file_get_contents("{$dir}/{$w}"), range(0, $workers - 1));
        array_map('unlink', glob("{$dir}/*") ?: []);
        rmdir($dir);

        return $results;
    }

    public function test_e2e5_two_simultaneous_requests_for_the_final_code_one_wins(): void
    {
        $this->skipUnlessRealDatabase();
        $offer = $this->offerWithCodes(1);

        $results = $this->race($offer, 2);

        $this->assertSame(1, count(array_filter($results, fn ($r) => str_starts_with($r, 'ok:'))), implode(' | ', $results));
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'err:offer_out_of_stock')), implode(' | ', $results));
        $this->assertSame(1, Voucher::count());
        $this->assertSame(['reserved' => 1], VoucherCode::selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all());
    }

    public function test_e2e5_eight_workers_three_codes_each_code_assigned_once(): void
    {
        $this->skipUnlessRealDatabase();
        $offer = $this->offerWithCodes(3);

        $results = $this->race($offer, 8);
        $wins = array_values(array_filter($results, fn ($r) => str_starts_with($r, 'ok:')));

        $this->assertCount(3, $wins, implode(' | ', $results));
        $this->assertCount(3, array_unique($wins), 'A code was handed out twice: '.implode(' | ', $wins));
        $this->assertSame(5, count(array_filter($results, fn ($r) => $r === 'err:offer_out_of_stock')), implode(' | ', $results));
        $this->assertSame(3, Voucher::count());
        $this->assertSame(3, Voucher::distinct()->count('voucher_code_id'));
        $this->assertSame(0, VoucherCode::where('status', 'available')->count());
    }

    public function test_e2e5_same_idempotency_key_from_parallel_requests_creates_one_voucher(): void
    {
        $this->skipUnlessRealDatabase();
        $offer = $this->offerWithCodes(5);
        $dir = sys_get_temp_dir().'/shopbonus-idem-'.uniqid();
        mkdir($dir);
        $startAt = microtime(true) + 0.5;
        DB::disconnect();

        $pids = [];
        foreach (range(0, 3) as $w) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    DB::reconnect();
                    usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));
                    $result = 'ok:'.app(VoucherInventory::class)->reserve(Offer::findOrFail($offer->id), VoucherOrigin::MerchantReward, 'order:777:offer:1')->id;
                } catch (Throwable $e) {
                    $result = 'err:'.$e->getMessage();
                }
                file_put_contents("{$dir}/{$w}", $result);
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        $results = array_map(fn ($w) => (string) file_get_contents("{$dir}/{$w}"), range(0, 3));
        array_map('unlink', glob("{$dir}/*") ?: []);
        rmdir($dir);

        $this->assertCount(1, array_unique($results), 'Parallel replays got different vouchers: '.implode(' | ', $results));
        $this->assertStringStartsWith('ok:', $results[0]);
        $this->assertSame(1, Voucher::count());
        $this->assertSame(4, VoucherCode::where('status', 'available')->count());
    }
}
