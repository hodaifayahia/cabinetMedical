<?php

namespace Tests\Feature\Backups;

use App\Console\Commands\NativeApplyOfflineRestore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NativeOfflineRestoreCommandTest extends TestCase
{
    #[Test]
    public function the_hidden_apply_command_refuses_an_unsupervised_cli_invocation(): void
    {
        putenv('MEDISMART_NATIVE_RESTORE');

        $exitCode = Artisan::call('medismart:restore:native-apply', [
            'operation' => (string) Str::uuid(),
        ]);
        $output = Artisan::output();

        $this->assertSame(NativeApplyOfflineRestore::EXIT_REFUSED_NO_MUTATION, $exitCode);
        $this->assertStringContainsString('"status":"refused_no_mutation"', $output);
        $this->assertStringContainsString('avant toute modification des données actives', $output);
    }
}
