<?php

namespace App\Events;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * Invalidation-only wallet signal. Clients must re-fetch GET /api/v1/me/wallet
 * (or consumer/wallet); this payload is never financial proof.
 */
final class WalletBalanceChanged implements ShouldBroadcast
{
    public function __construct(private readonly string $userPublicId, private readonly string $reason) {}

    public static function forConsumerWallet(Wallet $wallet, string $reason): void
    {
        if ($wallet->owner_type !== 'user') {
            return;
        }
        $user = User::find($wallet->owner_id);
        if ($user === null) {
            return;
        }
        broadcast(new self($user->public_id, $reason));
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('shopbonus.user.'.$this->userPublicId.'.wallet')];
    }

    public function broadcastAs(): string
    {
        return 'WalletBalanceChanged';
    }

    /** @return array{reason: string} */
    public function broadcastWith(): array
    {
        return ['reason' => $this->reason];
    }
}
