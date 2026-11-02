<?php

namespace Tests\Feature\Reliability;

use App\Models\Offer;
use App\Models\QaTestRun;
use App\Models\User;
use App\Models\VoucherCheckout;
use App\Models\Webshop;
use App\Services\Disputes\DisputeService;
use App\Services\Qa\QaRuns;
use App\Services\Reliability\Recovery\Backups;
use App\Services\Reliability\Recovery\EncryptedArchive;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Tests\TestCase;

class RecoveryTest extends TestCase
{
    private string $database;

    private string $media;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'shopbonus-recovery-');
        $this->media = sys_get_temp_dir().'/shopbonus-media-'.bin2hex(random_bytes(6));
        mkdir($this->media, 0700);
        file_put_contents($this->media.'/sample.txt', 'SYNTHETIC critical media');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->database,
            'recovery.key' => base64_encode(random_bytes(32)), 'recovery.enabled' => true,
            'recovery.disk' => 'recovery-test', 'recovery.media_roots' => ['public' => $this->media]]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Storage::fake('recovery-test');
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        RefreshDatabaseState::$migrated = false;
        unlink($this->database);
        File::deleteDirectory($this->media);
        parent::tearDown();
    }

    public function test_every_private_upload_directory_is_a_backup_root(): void
    {
        $roots = array_values((require config_path('recovery.php'))['media_roots']);
        foreach (['cms', 'banners', 'avatars', 'public-profiles', DisputeService::EVIDENCE_DIR, QaRuns::EVIDENCE_DIR, 'verification-evidence'] as $dir) {
            $this->assertContains(storage_path('app/private/'.$dir), $roots, "{$dir} uploads are not backed up");
        }
    }

    public function test_restored_dispute_and_qa_references_resolve_to_identical_decryptable_attachments(): void
    {
        config(['filesystems.disks.evidence-fixture' => ['driver' => 'local', 'root' => $this->media.'/private']]);
        // Real local adapter under a temporary root, never the active private uploads.
        Storage::set('local', Storage::build(config('filesystems.disks.evidence-fixture')));
        config(['recovery.media_roots' => [
            'disputes' => $this->media.'/private/'.DisputeService::EVIDENCE_DIR,
            'qa' => $this->media.'/private/'.QaRuns::EVIDENCE_DIR,
        ]]);
        $user = User::factory()->create();
        $shop = Webshop::factory()->create();
        $offer = Offer::create(['webshop_id' => $shop->id, 'acquisition' => 'wallet_purchase', 'created_by' => $user->id]);
        $checkout = VoucherCheckout::create(['user_id' => $user->id, 'webshop_id' => $shop->id, 'offer_id' => $offer->id,
            'idempotency_key' => 'recovery-fixture', 'state' => 'paid_unfulfilled', 'price_minor' => 1000,
            'wallet_part_minor' => 0, 'payment_part_minor' => 1000, 'currency' => 'EUR', 'expires_at' => now()->addHour()]);
        $disputeBytes = 'SYNTHETIC dispute attachment';
        $dispute = app(DisputeService::class)->open($user, null, $checkout, 'missing_after_payment', 'Recovery fixture',
            [UploadedFile::fake()->createWithContent('evidence.txt', $disputeBytes)]);
        $run = QaTestRun::create(['suite' => 'feature', 'environment' => 'ci', 'git_sha' => str_repeat('a', 40),
            'source' => 'junit', 'report_hash' => hash('sha256', 'recovery-fixture'), 'totals' => []]);
        $qaBytes = 'SYNTHETIC quality evidence';
        $qa = app(QaRuns::class)->storeEvidence($run, UploadedFile::fake()->createWithContent('qa.txt', $qaBytes), null, null, $user->id);
        $backup = app(Backups::class)->create();
        $result = app(Backups::class)->restore($backup['archive']);
        try {
            $pdo = new PDO('sqlite:'.$result['isolated_directory'].'/database.sqlite');
            $restoredDispute = $pdo->query('SELECT public_id, evidence FROM voucher_disputes')->fetch(PDO::FETCH_ASSOC);
            $meta = json_decode($restoredDispute['evidence'], true, flags: JSON_THROW_ON_ERROR)[0];
            $disputeFile = $result['isolated_directory'].'/media/disputes/'.$restoredDispute['public_id'].'/'.$meta['id'].'.enc';
            $this->assertSame($disputeBytes, base64_decode(Crypt::decryptString(file_get_contents($disputeFile)), true));
            $restoredQa = $pdo->query('SELECT path, sha256 FROM qa_evidence')->fetch(PDO::FETCH_ASSOC);
            $qaFile = $result['isolated_directory'].'/media/qa/'.substr($restoredQa['path'], strlen(QaRuns::EVIDENCE_DIR) + 1);
            $bytes = base64_decode(Crypt::decryptString(file_get_contents($qaFile)), true);
            $this->assertSame($qaBytes, $bytes);
            $this->assertSame($restoredQa['sha256'], hash('sha256', $bytes));
            $this->assertSame(hash_file('sha256', Storage::disk('local')->path($qa->path)), hash_file('sha256', $qaFile));
            $this->assertSame($dispute->public_id, $restoredDispute['public_id']);
        } finally {
            unset($pdo);
            File::deleteDirectory($result['isolated_directory']);
        }
    }

    public function test_encrypted_database_and_media_restore_preserves_native_financial_records_in_isolation(): void
    {
        $wallets = app(WalletService::class);
        $user = User::factory()->create();
        $wallet = $wallets->consumerWallet($user);
        $wallets->credit($wallet, 700, 'synthetic-commission');
        $backup = app(Backups::class)->create();
        $encrypted = Storage::disk('recovery-test')->get('shopbonus-backups/'.$backup['archive'].'.sbx');
        $this->assertStringNotContainsString('SYNTHETIC critical media', $encrypted);
        $result = app(Backups::class)->restore($backup['archive']);
        try {
            $pdo = new PDO('sqlite:'.$result['isolated_directory'].'/database.sqlite');
            $this->assertSame(700, (int) $pdo->query('SELECT available FROM wallets')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM wallet_ledger_entries')->fetchColumn());
            $this->assertSame($user->id, (int) $pdo->query('SELECT owner_id FROM wallets')->fetchColumn());
            $this->assertSame('SYNTHETIC critical media', file_get_contents($result['isolated_directory'].'/media/public/sample.txt'));
            $this->assertSame(700, $wallet->fresh()->available);
            $this->assertDatabaseCount('wallet_ledger_entries', 1);
            $this->assertDatabaseHas('recovery_events', ['kind' => 'restore', 'status' => 'succeeded']);
        } finally {
            File::deleteDirectory($result['isolated_directory']);
        }
    }

    public function test_wrong_key_and_truncated_archive_fail_authentication_without_active_database_changes(): void
    {
        $backup = app(Backups::class)->create();
        $key = config('recovery.key');
        config(['recovery.key' => base64_encode(random_bytes(32))]);
        try {
            app(Backups::class)->restore($backup['archive']);
            $this->fail('Wrong key accepted.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('recovery_events', ['kind' => 'restore', 'status' => 'failed']);
        }
        config(['recovery.key' => $key]);
        $disk = Storage::disk('recovery-test');
        $path = 'shopbonus-backups/'.$backup['archive'].'.sbx';
        $disk->put($path, substr($disk->get($path), 0, -5));
        $this->expectException(RuntimeException::class);
        app(Backups::class)->restore($backup['archive']);
    }

    public function test_authenticated_traversal_manifest_is_rejected_before_restore(): void
    {
        $raw = $this->media.'/raw';
        $encrypted = $this->media.'/bad.sbx';
        $manifest = json_encode(['version' => 1, 'driver' => 'sqlite', 'database' => 'database.sqlite', 'files' => [['path' => 'media/public/../../escape', 'size' => 0, 'sha256' => hash('sha256', '')]]]);
        file_put_contents($raw, pack('N', strlen($manifest)).$manifest);
        app(EncryptedArchive::class)->encrypt($raw, $encrypted, base64_decode(config('recovery.key')));
        $id = strtolower((string) Str::ulid());
        Storage::disk('recovery-test')->put('shopbonus-backups/'.$id.'.sbx', file_get_contents($encrypted));
        $this->expectException(RuntimeException::class);
        app(Backups::class)->restore($id);
    }

    public function test_symlinks_and_disabled_schedule_are_not_silently_accepted(): void
    {
        config(['recovery.enabled' => false]);
        $this->artisan('shopbonus:backup')->assertFailed();
        if (! @symlink($this->database, $this->media.'/linked')) {
            $this->markTestSkipped('Symlinks require elevated privileges or Developer Mode on Windows.');
        }
        $this->expectException(RuntimeException::class);
        app(Backups::class)->create();
    }
}
