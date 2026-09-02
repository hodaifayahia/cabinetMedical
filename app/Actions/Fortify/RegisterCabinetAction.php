<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Services\Cabinet\CabinetProvisioningService;
use App\Support\Wilayas;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Provisions a brand-new cabinet in the pending state together with its owner
 * account, doctor profile, default weekly schedule and per-cabinet settings.
 *
 * The action is intentionally free of any request/session coupling so it can be
 * reused from controllers, console commands or the forthcoming API layer. It
 * owns the self-registration validation rules only; the write itself lives in
 * CabinetProvisioningService, which the platform-admin mobile API shares.
 */
class RegisterCabinetAction
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private readonly CabinetProvisioningService $provisioning,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(array $input): User
    {
        $data = Validator::make($input, [
            ...$this->profileRules(),
            'phone' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9][0-9\s().-]{7,24}$/'],
            'cabinet_name' => ['required', 'string', 'min:2', 'max:180'],
            'specialization' => ['required', 'string', 'min:2', 'max:150'],
            'wilaya' => ['required', 'integer', 'between:'.Wilayas::MIN.','.Wilayas::MAX],
            'password' => $this->passwordRules(),
        ], [
            'phone.regex' => 'Saisissez un numéro de téléphone valide.',
        ])->validate();

        return $this->provision($data);
    }

    /**
     * Create the cabinet and everything that hangs off it, or report clearly
     * that nothing was created.
     *
     * The whole provisioning runs in one transaction, so a failure part-way
     * through removes the cabinet and its owner again. Letting that surface as
     * a generic server error told the owner nothing, and the next screen they
     * reached was a sign-in form that rejected the account they believed they
     * had just created. A registration that did not happen now says so.
     *
     * @param  array<string, mixed>  $data
     */
    private function provision(array $data): User
    {
        try {
            /** @var array{name: string, email: string, password: string, phone: string, cabinet_name: string, specialization: string, wilaya: int|string} $data */
            return $this->provisioning->provision($data);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'cabinet_name' => 'La création du cabinet a échoué et rien n’a été enregistré. Aucun compte n’existe pour le moment : réessayez, puis contactez le support Drclick si le problème persiste.',
            ]);
        }
    }
}
