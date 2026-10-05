<?php

namespace Tests\Unit\Desktop;

use App\Models\Appointment;
use App\Services\Sync\ImportResult;
use App\Services\Sync\SyncReport;
use App\Services\Sync\SyncTransportException;
use Tests\TestCase;

class SyncReportTest extends TestCase
{
    public function test_import_results_describe_whether_they_changed_data(): void
    {
        $appointment = new Appointment;

        $this->assertTrue(ImportResult::created($appointment)->changedData());
        $this->assertTrue(ImportResult::updated($appointment)->changedData());
        $this->assertTrue(ImportResult::deleted($appointment)->changedData());
        $this->assertFalse(ImportResult::skipped('echo')->changedData());
        $this->assertFalse(ImportResult::rejected('invalid')->changedData());
    }

    public function test_import_results_carry_their_appointment_and_reason(): void
    {
        $appointment = new Appointment;

        $created = ImportResult::created($appointment);
        $rejected = ImportResult::rejected('unknown_doctor');

        $this->assertSame(ImportResult::OUTCOME_CREATED, $created->outcome);
        $this->assertSame($appointment, $created->appointment);
        $this->assertNull($created->reason);
        $this->assertFalse($created->wasRejected());
        $this->assertSame('unknown_doctor', $rejected->reason);
        $this->assertNull($rejected->appointment);
        $this->assertTrue($rejected->wasRejected());
    }

    public function test_a_new_report_is_quiet_and_successful(): void
    {
        $report = new SyncReport;

        $this->assertFalse($report->failed());
        $this->assertTrue($report->wasQuiet());
        $this->assertSame([
            'pulled' => 0,
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'conflicts' => 0,
            'pushed' => 0,
            'rejections' => [],
            'offline' => false,
            'error' => null,
        ], $report->toArray());
    }

    public function test_recorded_results_are_tallied_by_outcome(): void
    {
        $report = new SyncReport;
        $appointment = new Appointment;

        $report->record(ImportResult::created($appointment));
        $report->record(ImportResult::created($appointment));
        $report->record(ImportResult::updated($appointment));
        $report->record(ImportResult::deleted($appointment));
        $report->record(ImportResult::skipped('echo'));
        $report->record(ImportResult::skipped('version_conflict'));
        $report->record(ImportResult::rejected('unknown_doctor'));
        $report->record(ImportResult::rejected('invalid_slot'));

        $this->assertSame(2, $report->created);
        $this->assertSame(1, $report->updated);
        $this->assertSame(1, $report->deleted);
        $this->assertSame(2, $report->skipped);
        $this->assertSame(1, $report->conflicts);
        $this->assertSame(['unknown_doctor', 'invalid_slot'], $report->rejections);
        $this->assertFalse($report->wasQuiet());
    }

    public function test_skips_and_rejections_alone_keep_the_run_quiet(): void
    {
        $report = new SyncReport;
        $report->record(ImportResult::skipped('echo'));
        $report->record(ImportResult::rejected('invalid'));
        $report->pulled = 2;

        $this->assertTrue($report->wasQuiet());
    }

    public function test_a_push_makes_the_run_not_quiet(): void
    {
        $report = new SyncReport;
        $report->pushed = 1;

        $this->assertFalse($report->wasQuiet());
    }

    public function test_an_error_marks_the_run_failed_and_not_quiet(): void
    {
        $report = new SyncReport;
        $report->error = 'Le service en ligne est injoignable.';
        $report->offline = true;

        $this->assertTrue($report->failed());
        $this->assertFalse($report->wasQuiet());
        $this->assertTrue($report->toArray()['offline']);
        $this->assertSame('Le service en ligne est injoignable.', $report->toArray()['error']);
    }

    public function test_transport_exceptions_distinguish_offline_from_refusal(): void
    {
        $offline = new SyncTransportException('hors ligne', offline: true);
        $mismatch = new SyncTransportException('autre cabinet', reason: SyncTransportException::REASON_CABINET_MISMATCH);

        $this->assertTrue($offline->offline);
        $this->assertNull($offline->reason);
        $this->assertSame('hors ligne', $offline->getMessage());
        $this->assertFalse($mismatch->offline);
        $this->assertSame('cabinet_mismatch', $mismatch->reason);
    }
}
