@props([
    'branding',
    'includeReceiptFooter' => false,
])

@php
    $receiptFooter = $includeReceiptFooter ? $branding['receipt_footer'] : '';
    $receiptFooterIsAlreadyIncluded = $receiptFooter !== ''
        && $receiptFooter === $branding['footer_extra_line'];
@endphp

@if ($branding['footer'] || ($receiptFooter && ! $receiptFooterIsAlreadyIncluded))
    <footer {{ $attributes->class(['document-branding-footer']) }}>
        @if ($branding['footer'])
            <p>{{ $branding['footer'] }}</p>
        @endif
        @if ($receiptFooter && ! $receiptFooterIsAlreadyIncluded)
            <p>{{ $receiptFooter }}</p>
        @endif
    </footer>
@endif
