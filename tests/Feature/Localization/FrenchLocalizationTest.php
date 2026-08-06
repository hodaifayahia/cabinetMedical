<?php

namespace Tests\Feature\Localization;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class FrenchLocalizationTest extends TestCase
{
    public function test_the_application_uses_french_user_facing_framework_messages(): void
    {
        $this->assertSame('fr', app()->getLocale());
        $this->assertSame('« Précédent', trans('pagination.previous'));
        $this->assertSame(
            'Ces identifiants ne correspondent à aucun compte.',
            trans('auth.failed'),
        );

        $validator = Validator::make(
            ['first_name' => '', 'email' => 'adresse-invalide'],
            ['first_name' => ['required'], 'email' => ['email']],
        );

        $this->assertSame(
            'Le champ prénom est obligatoire.',
            $validator->errors()->first('first_name'),
        );
        $this->assertSame(
            'Le champ adresse e-mail doit contenir une adresse e-mail valide.',
            $validator->errors()->first('email'),
        );
    }
}
