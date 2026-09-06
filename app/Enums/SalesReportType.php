<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The sales reporting surface (WP-2.8, GAP-MW-17, GAP-UI-05, SL-15).
 *
 * Mirrors {@see InventoryReportType}'s shape: one case per report, each mapped to the
 * {@see SalesPermission} that gates it. `DeliveredNotInvoiced` and `InvoicedNotCollected` are the
 * two named leak points in SL-15's Quotation -> Delivery -> Invoice -> Payment model.
 */
enum SalesReportType: string
{
    case QuotationFunnel = 'quotation_funnel';
    case WinLossAnalysis = 'win_loss_analysis';
    case ConversionVelocity = 'conversion_velocity';
    case DeliveredNotInvoiced = 'delivered_not_invoiced';
    case InvoicedNotCollected = 'invoiced_not_collected';
    case TaxRecognitionSummary = 'tax_recognition_summary';
    case DiscountAndFloorOverrides = 'discount_and_floor_overrides';
    case ReturnsWithoutCredit = 'returns_without_credit';
    case CustomerRevenue = 'customer_revenue';

    public function sourcePermission(): SalesPermission
    {
        return SalesPermission::ReportView;
    }

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->headline()->toString();
    }
}
