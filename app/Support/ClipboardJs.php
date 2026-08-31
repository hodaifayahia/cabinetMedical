<?php

namespace App\Support;

use Illuminate\Support\Js;
use Illuminate\View\ComponentAttributeBag;

/**
 * Builds the JavaScript that copies a value to the clipboard from a Filament
 * Alpine click handler.
 *
 * Two constraints make the obvious one-liner wrong, and both were live bugs:
 *
 * 1. The handler is rendered as an HTML attribute through
 *    {@see ComponentAttributeBag::__toString()}, which escapes
 *    a double quote as `\"`. A backslash is not an HTML escape, so the parser
 *    ends the attribute at the first raw quote and Alpine receives a truncated
 *    expression. `json_encode()` emits raw quotes and therefore cannot be used
 *    here; {@see Js::from()} encodes with JSON_HEX_QUOT and wraps in single
 *    quotes, so its output survives the attribute intact.
 *
 * 2. Alpine compiles the handler in *expression* position. A bare `var`
 *    statement is a syntax error there, so the whole body is wrapped in an
 *    immediately-invoked function expression.
 *
 * `navigator.clipboard` is undefined outside a secure context (plain http on a
 * LAN address), so the `execCommand` path is a real fallback, not dead code.
 */
final class ClipboardJs
{
    /**
     * A single JavaScript expression that copies $value to the clipboard.
     */
    public static function copy(string $value): string
    {
        $encoded = Js::from($value);

        return '(function(){'
            ."var value={$encoded};"
            .'var fallback=function(){'
            ."var field=document.createElement('textarea');"
            .'field.value=value;'
            ."field.setAttribute('readonly','');"
            // Keep it in the viewport but invisible: a detached or off-screen
            // node cannot be selected, and execCommand copies the selection.
            ."field.style.position='fixed';"
            ."field.style.top='0';"
            ."field.style.opacity='0';"
            .'document.body.appendChild(field);'
            .'field.focus();'
            .'field.select();'
            ."try{document.execCommand('copy')}catch(error){}"
            .'document.body.removeChild(field)};'
            .'if(navigator.clipboard&&window.isSecureContext){'
            .'navigator.clipboard.writeText(value).catch(fallback)}'
            .'else{fallback()}'
            .'})()';
    }
}
