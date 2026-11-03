<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_disputes', function (Blueprint $table) {
            $table->string('compensation_status', 16)->nullable();
            $table->string('compensation_request_key', 160)->nullable();
        });
        DB::table('voucher_disputes')->whereNotNull('compensated_at')->update(['compensation_status' => 'completed']);
        // Repair earlier accepted refunds without inventing provider completion.
        DB::table('voucher_disputes')->where('compensation', 'refund')->orderBy('id')->each(function ($d) {
            $payment = DB::table('voucher_checkout_payments')->where('refund_ref', $d->compensation_ref)->first();
            if ($payment === null) {
                DB::table('voucher_disputes')->where('id', $d->id)->update(['compensation_status' => 'requested', 'compensated_at' => null]);

                return;
            }
            $status = $payment->refund_status === 'refunded' ? 'completed' : (in_array($payment->refund_status, ['failed', 'canceled'], true) ? 'failed' : 'pending');
            DB::table('voucher_disputes')->where('id', $d->id)->update(['compensation_status' => $status] + ($status === 'completed' ? [] : ['compensated_at' => null]));
        });
    }

    public function down(): void
    {
        Schema::table('voucher_disputes', fn (Blueprint $table) => $table->dropColumn(['compensation_status', 'compensation_request_key']));
    }
};
