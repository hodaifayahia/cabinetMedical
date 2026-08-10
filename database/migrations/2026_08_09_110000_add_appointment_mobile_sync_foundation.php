<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique()->after('id');
            $table->unsignedBigInteger('sync_version')->default(1)->after('public_id');
            $table->char('mobile_idempotency_key_hash', 64)->nullable()->after('sync_version');
            $table->char('mobile_idempotency_fingerprint', 64)->nullable()->after('mobile_idempotency_key_hash');
            $table->softDeletes();

            $table->unique(
                ['cabinet_id', 'mobile_idempotency_key_hash'],
                'appointments_cabinet_mobile_idempotency_unique',
            );
        });

        Schema::create('appointment_sync_events', function (Blueprint $table): void {
            // The incrementing key is the per-database cursor. Cabinet scoping
            // makes it safe for a client to observe gaps belonging to others.
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('cabinet_id')->constrained('cabinets')->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->uuid('appointment_public_id');
            $table->unsignedBigInteger('version');
            $table->string('action', 20);
            $table->json('payload');
            $table->char('payload_sha256', 64);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('last_attempted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['cabinet_id', 'appointment_public_id', 'version'],
                'appointment_sync_events_aggregate_version_unique',
            );
            $table->index(['cabinet_id', 'id'], 'appointment_sync_events_cabinet_cursor_index');
            $table->index(['cabinet_id', 'status'], 'appointment_sync_events_cabinet_status_index');
        });

        $now = now();

        DB::table('appointments')
            ->orderBy('id')
            ->chunkById(200, function ($appointments) use ($now): void {
                foreach ($appointments as $appointment) {
                    /** @var array<string, mixed> $appointmentData */
                    $appointmentData = (array) $appointment;
                    $appointmentId = (int) $appointmentData['id'];
                    $publicId = (string) Str::uuid7();

                    DB::table('appointments')
                        ->where('id', $appointmentId)
                        ->update([
                            'public_id' => $publicId,
                            'sync_version' => 1,
                        ]);

                    if ($appointmentData['cabinet_id'] === null) {
                        continue;
                    }

                    $payload = $this->payload($appointmentData, $publicId, 1, null);
                    $encodedPayload = json_encode(
                        $payload,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    );

                    DB::table('appointment_sync_events')->insert([
                        'event_id' => (string) Str::uuid7(),
                        'cabinet_id' => $appointmentData['cabinet_id'],
                        'appointment_id' => $appointmentId,
                        'appointment_public_id' => $publicId,
                        'version' => 1,
                        'action' => 'upsert',
                        'payload' => $encodedPayload,
                        'payload_sha256' => hash('sha256', $encodedPayload),
                        'status' => 'pending',
                        'attempts' => 1,
                        'last_attempted_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_sync_events');

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropUnique('appointments_cabinet_mobile_idempotency_unique');
            $table->dropUnique(['public_id']);
            $table->dropColumn([
                'public_id',
                'sync_version',
                'mobile_idempotency_key_hash',
                'mobile_idempotency_fingerprint',
                'deleted_at',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $appointment
     * @return array<string, mixed>
     */
    private function payload(array $appointment, string $publicId, int $version, ?string $deletedAt): array
    {
        return [
            'public_id' => $publicId,
            'legacy_id' => (int) $appointment['id'],
            'patient_id' => (int) $appointment['patient_id'],
            'appointment_date' => $appointment['appointment_date'],
            'starts_at' => $appointment['starts_at'],
            'ends_at' => $appointment['ends_at'],
            'status' => $appointment['status'],
            'reason' => $appointment['reason'],
            'prestation' => $appointment['prestation'] ?? null,
            'reception_notes' => $appointment['reception_notes'],
            'cancellation_reason' => $appointment['cancellation_reason'],
            'confirmed_at' => $appointment['confirmed_at'],
            'checked_in_at' => $appointment['checked_in_at'],
            'started_at' => $appointment['started_at'],
            'completed_at' => $appointment['completed_at'],
            'cancelled_at' => $appointment['cancelled_at'],
            'deleted_at' => $deletedAt,
            'version' => $version,
            'created_at' => $appointment['created_at'],
            'updated_at' => $appointment['updated_at'],
        ];
    }
};
