<?php

namespace App\Enums;

/**
 * Connection states shown to merchants (Trello #1). `Connected` is reached
 * only after all seven credential-bound native verification gates, never from saved credentials or a complete webhook alone.
 */
enum ConnectionStatus: string
{
    case SetupRequired = 'setup_required';
    case Testing = 'testing';
    case Connected = 'connected';
    case Limited = 'limited';
    case Error = 'error';
    case Disconnected = 'disconnected';

    public function receivesEvents(): bool
    {
        return $this !== self::Disconnected;
    }
}
