<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * The form never exposes tenancy, and the platform flags are written with
     * forceFill because `email_verified_at` is deliberately not mass
     * assignable on the User model. An account created here is therefore a
     * platform account by construction: it can never be silently attached to
     * a cabinet, nor land outside this resource's own listing.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = new User;

        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_platform_admin' => true,
            'cabinet_id' => null,
            'cabinet_setting_id' => null,
            // A platform account is provisioned by an administrator who
            // already controls the address; there is no verification e-mail
            // to wait for before the account can be used.
            'email_verified_at' => now(),
            'approved_at' => now(),
        ])->save();

        return $user;
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->getRecord();

        AuditLog::record('platform.account_created', $user, [
            'email' => $user->email,
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
