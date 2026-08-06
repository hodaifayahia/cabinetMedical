<?php

namespace App\View\Components;

use Illuminate\Support\Facades\Vite;
use Inertia\View\Components\App as InertiaApp;

final class NonceBoundInertiaApp extends InertiaApp
{
    public string $nonce;

    public function __construct(string $id = 'app')
    {
        parent::__construct($id);

        $nonce = Vite::cspNonce();

        if (! is_string($nonce) || $nonce === '') {
            $nonce = Vite::useCspNonce();
        }

        $this->nonce = $nonce;
    }

    public function render(): string
    {
        return <<<'blade'
@if($response)
{!! $response->body !!}
@else
<script nonce="{{ $nonce }}" data-page="{{ $id }}" type="application/json">{!! $pageJson !!}</script><div id="{{ $id }}"></div>
@endif
blade;
    }
}
