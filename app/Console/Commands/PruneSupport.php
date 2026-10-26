<?php

namespace App\Console\Commands;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Services\Support\SupportChat;
use Illuminate\Console\Command;

class PruneSupport extends Command
{
    protected $signature = 'shopbonus:prune-support';

    protected $description = 'Expire retained support conversations and recover timed-out research';

    public function handle(SupportChat $chat): int
    {
        SupportMessage::whereIn('status', ['RESEARCHING', 'RETRYING', 'VERIFYING'])->where('updated_at', '<', now()->subMinutes(2))->orderBy('id')
            ->chunkById(50, function ($rows) use ($chat) {
                foreach ($rows as $m) {
                    $chat->failed($m->id);
                }
            });
        SupportMessage::where('status', 'PENDING')->where('created_at', '<', now()->subHour())->orderBy('id')
            ->chunkById(50, function ($rows) use ($chat) {
                foreach ($rows as $m) {
                    $chat->failed($m->id);
                }
            });
        SupportConversation::where('expires_at', '<=', now())->orderBy('id')->chunkById(50, function ($rows) {
            foreach ($rows as $c) {
                if (! SupportMessage::where('conversation_id', $c->id)->whereIn('status', SupportChat::ACTIVE)->exists()) {
                    $c->delete();
                }
            }
        });
        $this->info('Support retention and recovery completed.');

        return self::SUCCESS;
    }
}
