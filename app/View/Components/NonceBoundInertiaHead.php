<?php

namespace App\View\Components;

use Illuminate\Support\Facades\Vite;
use Inertia\View\Components\Head as InertiaHead;

final class NonceBoundInertiaHead extends InertiaHead
{
    public function __construct()
    {
        parent::__construct();

        if ($this->response === null) {
            return;
        }

        $nonce = Vite::cspNonce();

        if (! is_string($nonce) || $nonce === '') {
            $nonce = Vite::useCspNonce();
        }

        // The Vite Inertia SSR endpoint returns its own body markup, including
        // the application/json bootstrap script. Bind any SSR script or style
        // element to the same response nonce before either fragment is emitted.
        $this->response->head = $this->nonceElements((string) $this->response->head, $nonce);
        $this->response->body = $this->nonceElements((string) $this->response->body, $nonce);
    }

    private function nonceElements(string $html, string $nonce): string
    {
        $nonceBound = preg_replace_callback(
            '/<(script|style)\b(?![^>]*\bnonce\s*=)([^>]*)>/i',
            static fn (array $matches): string => '<'.$matches[1].' nonce="'.$nonce.'"'.$matches[2].'>',
            $html,
        );

        return is_string($nonceBound) ? $nonceBound : $html;
    }
}
