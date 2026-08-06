<?php

namespace App\ClinicalDocuments;

use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use JsonException;
use Throwable;

final class ClinicalDocumentOnlyOffice
{
    private const MAX_DOCUMENT_BYTES = 20 * 1024 * 1024;

    /**
     * @return array<string, mixed>
     */
    public function payload(Document $document, Request $request, bool $canEdit): array
    {
        if ($document->file_path === null) {
            return [
                'id' => $document->getKey(),
                'category' => $document->category,
                'title' => $document->title,
                'created_at' => $document->created_at?->toDateString(),
                'paper_size' => $document->paper_size,
                'has_word_file' => false,
                'editor_config' => null,
                'download_url' => null,
            ];
        }

        $config = [
            'document' => [
                'fileType' => 'docx',
                'key' => $this->documentKey($document),
                'permissions' => [
                    'download' => true,
                    'edit' => $canEdit,
                    'print' => true,
                ],
                'title' => $document->original_filename ?? $document->title.'.docx',
                'url' => $this->signedDocumentUrl('clinical-documents.file', $document),
            ],
            'documentType' => 'word',
            'editorConfig' => [
                'callbackUrl' => $this->signedDocumentUrl('clinical-documents.callback', $document),
                'customization' => ['forcesave' => true],
                'lang' => 'fr',
                'mode' => $canEdit ? 'edit' : 'view',
                'user' => [
                    'id' => (string) $request->user()->getAuthIdentifier(),
                    'name' => $request->user()->name,
                ],
            ],
            'height' => '100%',
            'type' => 'desktop',
            'width' => '100%',
        ];

        $secret = config('onlyoffice.jwt_secret');

        if ($secret !== '') {
            $config['token'] = $this->encodeJwt($config, $secret);
        }

        return [
            'id' => $document->getKey(),
            'category' => $document->category,
            'title' => $document->title,
            'created_at' => $document->created_at?->toDateString(),
            'paper_size' => $document->paper_size,
            'has_word_file' => true,
            'editor_config' => $config,
            'download_url' => $this->signedDocumentUrl(
                'clinical-documents.file',
                $document,
                config('app.url'),
            ),
        ];
    }

    public function callback(Request $request, Document $document): JsonResponse
    {
        if (! $request->hasValidRelativeSignature() || ! $this->hasValidCallbackToken($request)) {
            return response()->json(['error' => 1], 401);
        }

        $status = (int) $request->input('status');

        if (! in_array($status, [2, 6], true)) {
            return response()->json(['error' => 0]);
        }

        if ($document->file_path === null || $request->string('key')->toString() !== $this->documentKey($document)) {
            return response()->json(['error' => 1], 409);
        }

        $documentUrl = $request->string('url')->toString();

        if (! $this->isTrustedDocumentServerUrl($documentUrl)) {
            return response()->json(['error' => 1], 422);
        }

        try {
            $response = Http::connectTimeout(5)
                ->timeout(30)
                ->withOptions(['allow_redirects' => false])
                ->get($documentUrl);
            $contents = $response->body();

            if (! $response->successful() || $contents === '' || strlen($contents) > self::MAX_DOCUMENT_BYTES) {
                return response()->json(['error' => 1], 502);
            }

            if (! Storage::put($document->file_path, $contents)) {
                return response()->json(['error' => 1], 500);
            }

            $updates = ['file_size' => strlen($contents)];

            if ($status === 2) {
                $updates['file_version'] = $document->file_version + 1;
            }

            $document->update($updates);
        } catch (Throwable $exception) {
            Log::warning('ONLYOFFICE clinical document callback failed.', [
                'document_id' => $document->getKey(),
                'exception' => $exception->getMessage(),
            ]);

            return response()->json(['error' => 1], 502);
        }

        return response()->json(['error' => 0]);
    }

    public function documentKey(Document $document): string
    {
        return 'clinical-document-'.$document->getKey().'-v'.$document->file_version;
    }

    private function signedDocumentUrl(string $route, Document $document, ?string $baseUrl = null): string
    {
        $relativeUrl = URL::temporarySignedRoute(
            $route,
            now()->addDay(),
            ['document' => $document],
            false,
        );

        return rtrim($baseUrl ?? config('onlyoffice.app_url'), '/').'/'.ltrim($relativeUrl, '/');
    }

    private function isTrustedDocumentServerUrl(string $url): bool
    {
        $origin = $this->urlOrigin($url);

        if ($origin === null) {
            return false;
        }

        $trustedOrigins = array_filter([
            $this->urlOrigin(config('onlyoffice.url')),
            $this->urlOrigin(config('onlyoffice.internal_url')),
        ]);

        return in_array($origin, $trustedOrigins, true);
    }

    private function urlOrigin(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }

        $port = $parts['port'] ?? (($parts['scheme'] === 'https') ? 443 : 80);

        return strtolower($parts['scheme'].'://'.$parts['host'].':'.$port);
    }

    private function hasValidCallbackToken(Request $request): bool
    {
        $secret = config('onlyoffice.jwt_secret');

        if ($secret === '') {
            return true;
        }

        $token = $request->bearerToken() ?: $request->string('token')->toString();
        $claims = $this->decodeJwt($token, $secret);

        if ($claims === null) {
            return false;
        }

        $payload = is_array($claims['payload'] ?? null) ? $claims['payload'] : $claims;

        foreach (['key', 'status'] as $field) {
            if (! array_key_exists($field, $payload) || (string) $payload[$field] !== (string) $request->input($field)) {
                return false;
            }
        }

        return ! isset($payload['url']) || hash_equals((string) $payload['url'], $request->string('url')->toString());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeJwt(array $payload, string $secret): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$body, $secret, true));

        return $header.'.'.$body.'.'.$signature;
    }

    /** @return array<string, mixed>|null */
    private function decodeJwt(string $token, string $secret): ?array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            return null;
        }

        [$header, $body, $signature] = $segments;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$body, $secret, true));

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        try {
            $decodedHeader = json_decode($this->base64UrlDecode($header), true, flags: JSON_THROW_ON_ERROR);
            $decodedBody = json_decode($this->base64UrlDecode($body), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return ($decodedHeader['alg'] ?? null) === 'HS256' && is_array($decodedBody)
            ? $decodedBody
            : null;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;

        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
