<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    public function __construct(
        private readonly RegisterCabinetAction $registerCabinet,
    ) {}

    /**
     * Validate and create a newly registered cabinet owner.
     *
     * Registration now provisions a brand-new cabinet (in the pending state)
     * rather than gating on a first-run singleton. The owner is created but is
     * held out of the application by the EnsureCabinetIsActive middleware until
     * platform staff activate the cabinet.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        return $this->registerCabinet->execute($input);
    }
}
