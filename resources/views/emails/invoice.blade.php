<p>Hello {{ $invoice->customer?->company_name ?? 'Customer' }},</p>
<p>Please find invoice <strong>{{ $invoice->invoice_number }}</strong> attached.</p>
<p>
    Invoice date: {{ $invoice->invoice_date?->format('Y-m-d') }}<br>
    Due date: {{ $invoice->due_date?->format('Y-m-d') }}<br>
    Total: {{ number_format((float) $invoice->total_amount, 2) }}
</p>
<p>Regards,<br>{{ config('app.name') }}</p>
