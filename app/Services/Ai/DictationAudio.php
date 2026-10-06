<?php

namespace App\Services\Ai;

use Illuminate\Http\UploadedFile;

/**
 * One recorded segment of a voice dictation, as the consultation screen (or a
 * desktop relaying it) uploads it. The audio is only forwarded to the speech
 * model: it is never stored.
 */
final class DictationAudio
{
    /**
     * What the recorder of a browser or desktop web view produces, under the
     * names the server's file type detection gives it (an audio-only WebM is
     * often detected as video/webm, an Ogg file as application/ogg).
     */
    private const MIME_TYPES = [
        'audio/webm', 'video/webm',
        'audio/ogg', 'application/ogg', 'audio/opus',
        'audio/mp4', 'video/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/aac',
        'audio/mpeg', 'audio/mp3',
        'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave',
    ];

    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        return [
            'required',
            'file',
            'mimetypes:'.implode(',', self::MIME_TYPES),
            'max:'.(int) ceil((int) config('ai.max_audio_bytes', 10 * 1024 * 1024) / 1024),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $field = 'audio'): array
    {
        return [
            $field.'.required' => 'Aucun enregistrement audio n’a été reçu.',
            $field.'.file' => 'L’enregistrement audio n’a pas pu être envoyé.',
            $field.'.uploaded' => 'L’enregistrement audio n’a pas pu être envoyé.',
            $field.'.mimetypes' => 'Format audio non pris en charge (WebM, Ogg, MP4, MP3 ou WAV).',
            $field.'.max' => 'Ce segment audio est trop long (10 Mo maximum).',
        ];
    }

    /**
     * The audio type to announce to the speech model.
     */
    public static function mimeOf(UploadedFile $file): string
    {
        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType()));

        return match (true) {
            str_contains($mime, 'webm') => 'audio/webm',
            str_contains($mime, 'ogg'), str_contains($mime, 'opus') => 'audio/ogg',
            str_contains($mime, 'mp4'), str_contains($mime, 'm4a'), str_contains($mime, 'aac') => 'audio/mp4',
            str_contains($mime, 'mpeg'), str_contains($mime, 'mp3') => 'audio/mpeg',
            str_contains($mime, 'wav') => 'audio/wav',
            default => 'audio/webm',
        };
    }
}
