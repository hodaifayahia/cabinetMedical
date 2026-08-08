<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

final class ProvisionPlatformSuperadmin extends Command
{
    protected $signature = 'platform:provision-superadmin
        {--email= : Adresse e-mail du superadministrateur}
        {--name= : Nom affiché du superadministrateur}
        {--generate-password : Générer et afficher une seule fois un mot de passe fort}';

    protected $description = 'Crée ou met à jour, sans rôle locataire, un superadministrateur de plateforme';

    public function handle(): int
    {
        $email = Str::lower($this->requiredValue('email', 'Adresse e-mail'));
        $name = $this->requiredValue('name', 'Nom affiché');
        $generatedPassword = (bool) $this->option('generate-password');
        $password = $this->resolvePassword($generatedPassword);

        if ($password === null) {
            return self::FAILURE;
        }

        $validator = Validator::make(
            compact('email', 'name', 'password'),
            [
                'email' => ['required', 'string', 'email', 'max:255'],
                'name' => ['required', 'string', 'min:2', 'max:255'],
                'password' => [
                    'required',
                    'string',
                    'max:255',
                    Password::min(16)->letters()->mixedCase()->numbers()->symbols(),
                ],
            ],
        );

        if ($validator->fails()) {
            $this->components->error('Les informations fournies ne sont pas valides.');

            foreach ($validator->errors()->all() as $error) {
                $this->line(" - {$error}");
            }

            return self::FAILURE;
        }

        try {
            [$user, $created] = DB::transaction(function () use ($email, $name, $password): array {
                $user = User::query()
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->lockForUpdate()
                    ->first();

                if ($user !== null && (! $user->is_platform_admin || $user->cabinet_id !== null)) {
                    throw new RuntimeException(
                        'Cette adresse appartient déjà à un compte non-plateforme ou rattaché à un cabinet.',
                    );
                }

                $created = $user === null;
                $user ??= new User;

                $user->forceFill([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                    'is_platform_admin' => true,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'approved_at' => $user->approved_at ?? now(),
                ])->save();

                return [$user, $created];
            });
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $action = $created ? 'créé' : 'mis à jour';
        $this->components->info("Superadministrateur de plateforme {$action} : {$user->email}");

        if ($generatedPassword) {
            $this->output->writeln(
                "Mot de passe initial (affiché une seule fois) : {$password}",
                OutputInterface::OUTPUT_RAW,
            );
            $this->components->warn('Copiez ce mot de passe maintenant et transmettez-le par un canal sécurisé.');
        }

        return self::SUCCESS;
    }

    private function requiredValue(string $option, string $question): string
    {
        $value = trim((string) $this->option($option));

        if ($value !== '' || ! $this->input->isInteractive()) {
            return $value;
        }

        return trim((string) $this->ask($question));
    }

    private function resolvePassword(bool $generate): ?string
    {
        if ($generate) {
            return Str::password(24);
        }

        if (! $this->input->isInteractive()) {
            $this->components->error(
                'En mode non interactif, utilisez --generate-password. Aucun mot de passe en clair n’est accepté en option.',
            );

            return null;
        }

        $password = (string) $this->secret('Mot de passe (16 caractères minimum)');
        $confirmation = (string) $this->secret('Confirmez le mot de passe');

        if (! hash_equals($password, $confirmation)) {
            $this->components->error('La confirmation du mot de passe ne correspond pas.');

            return null;
        }

        return $password;
    }
}
