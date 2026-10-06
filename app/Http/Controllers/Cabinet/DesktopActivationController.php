<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Licensing\DesktopLicenseActivator;
use App\Models\Cabinet;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * The two other ways a cabinet owner activates an installed desktop from the
 * activation screen: with the cabinet's online account (which also links the
 * poste for mobile appointments), or fully offline with a signed licence
 * file sent by Drclick.
 */
class DesktopActivationController extends Controller
{
    /** Largest envelope the verifier accepts. */
    private const MAXIMUM_LICENSE_FILE_KILOBYTES = 128;

    public function onlineAccount(Request $request, DesktopLicenseActivator $activator): RedirectResponse
    {
        [$user, $cabinet] = $this->owner($request);

        $data = $request->validate([
            'online_email' => ['required', 'email', 'max:190'],
            'online_password' => ['required', 'string', 'max:255'],
        ], [
            'online_email.required' => 'Saisissez l’adresse e-mail de votre compte Drclick en ligne.',
            'online_password.required' => 'Saisissez le mot de passe de votre compte Drclick en ligne.',
        ]);

        $result = $activator->activateWithOnlineAccount($cabinet, $user, $data['online_email'], $data['online_password']);

        $message = 'Licence activée. Ce poste fonctionne désormais sans Internet.';
        $message .= $result['linked']
            ? ' Il est aussi relié au service en ligne : les rendez-vous pris dans l’application mobile arriveront automatiquement.'
            : ' Reliez-le au service en ligne dans Configuration › Service en ligne pour recevoir les rendez-vous mobiles.';

        return to_route('dashboard')->with('success', $message);
    }

    public function licenseFile(Request $request, DesktopLicenseActivator $activator): RedirectResponse
    {
        [, $cabinet] = $this->owner($request);

        $request->validate([
            'entitlement_file' => ['required_without:entitlement', 'nullable', 'file', 'max:'.self::MAXIMUM_LICENSE_FILE_KILOBYTES],
            'entitlement' => ['required_without:entitlement_file', 'nullable', 'string', 'max:131072'],
        ], [
            'entitlement_file.required_without' => 'Choisissez le fichier de licence reçu de Drclick.',
            'entitlement.required_without' => 'Choisissez le fichier de licence reçu de Drclick.',
            'entitlement_file.max' => 'Ce fichier est trop volumineux pour être un fichier de licence Drclick.',
        ]);

        $file = $request->file('entitlement_file');
        $envelope = $file instanceof UploadedFile
            ? (string) file_get_contents($file->getRealPath())
            : (string) $request->input('entitlement');

        $activator->applyLicenseFile($cabinet, $envelope);

        return to_route('dashboard')->with(
            'success',
            'Licence activée depuis le fichier. Ce poste fonctionne sans Internet.',
        );
    }

    /**
     * @return array{0: User, 1: Cabinet}
     */
    private function owner(Request $request): array
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User
            && $user->cabinet instanceof Cabinet
            && $user->cabinet->owner_user_id === $user->getKey(),
            403,
            'Seul le titulaire du cabinet peut activer sa licence.',
        );

        return [$user, $user->cabinet];
    }
}
