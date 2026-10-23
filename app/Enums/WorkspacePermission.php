<?php

namespace App\Enums;

/**
 * Granular workspace permissions (Trello #16 "dashboard, orders,
 * integrations, vouchers, team and settings"). Values are stored in
 * membership rows: add new cases freely, never rename existing ones.
 */
enum WorkspacePermission: string
{
    case DashboardView = 'dashboard.view';
    case OrdersView = 'orders.view';
    case OrdersManage = 'orders.manage';
    case IntegrationsView = 'integrations.view';
    case IntegrationsManage = 'integrations.manage';
    case OffersView = 'offers.view';
    case OffersManage = 'offers.manage';
    case VouchersView = 'vouchers.view';
    case VouchersManage = 'vouchers.manage';
    case TeamView = 'team.view';
    case TeamManage = 'team.manage';
    case SettingsView = 'settings.view';
    case SettingsManage = 'settings.manage';
    case FinanceView = 'finance.view';
    case FinanceManage = 'finance.manage';
    // Owner-only: legal and ownership actions.
    case AgreementsAccept = 'agreements.accept';
    case OwnershipManage = 'ownership.manage';

    public function isOwnerOnly(): bool
    {
        return in_array($this, [self::AgreementsAccept, self::OwnershipManage, self::FinanceManage], true);
    }
}
