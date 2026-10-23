<?php

namespace App\Enums;

/** Ledger event types (Trello #19 "Central wallet"). */
enum LedgerEntryType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
    case Reserve = 'reserve';
    case Release = 'release';
    case Expire = 'expire';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';
}
