<?php

namespace Tests\Unit\Backups;

use App\Backups\BackupSchedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BackupScheduleTest extends TestCase
{
    #[Test]
    public function it_keeps_three_distinct_times_sorted_and_falls_back_to_the_defaults(): void
    {
        $this->assertSame(['08:00', '12:30', '19:45'], BackupSchedule::normalize(['19:45', '08:00', '12:30']));

        foreach ([
            null,
            '10:00',
            ['10:00', '14:00'],
            ['10:00', '14:00', '18:00', '22:00'],
            ['10:00', '10:00', '18:00'],
            ['10:00', '14:00', '7:00'],
            ['10:00', '14:00', '24:00'],
            ['a' => '10:00', 'b' => '14:00', 'c' => '18:00'],
        ] as $invalid) {
            $this->assertNull(BackupSchedule::normalize($invalid));
            $this->assertSame(BackupSchedule::DEFAULT_TIMES, BackupSchedule::fromSetting($invalid)->times());
        }
    }

    #[Test]
    public function the_due_slot_is_the_latest_passed_one_including_yesterdays_last(): void
    {
        $schedule = BackupSchedule::fromSetting(['09:00', '13:00', '18:30']);
        $zone = 'Africa/Algiers';
        $at = static fn (string $time): CarbonImmutable => CarbonImmutable::parse($time, $zone);

        $this->assertEquals($at('2026-09-29 18:30'), $schedule->latestDueSlot($at('2026-09-30 08:59')));
        $this->assertEquals($at('2026-09-30 09:00'), $schedule->latestDueSlot($at('2026-09-30 09:00')));
        $this->assertEquals($at('2026-09-30 13:00'), $schedule->latestDueSlot($at('2026-09-30 17:00')));
        $this->assertEquals($at('2026-09-30 18:30'), $schedule->latestDueSlot($at('2026-09-30 23:59')));
        // Month and year boundaries.
        $this->assertEquals($at('2026-12-31 18:30'), $schedule->latestDueSlot($at('2027-01-01 00:10')));
    }

    #[Test]
    public function the_next_slot_is_strictly_after_now(): void
    {
        $schedule = BackupSchedule::fromSetting(['09:00', '13:00', '18:30']);
        $zone = 'Africa/Algiers';
        $at = static fn (string $time): CarbonImmutable => CarbonImmutable::parse($time, $zone);

        $this->assertEquals($at('2026-09-30 09:00'), $schedule->nextSlot($at('2026-09-30 06:00')));
        $this->assertEquals($at('2026-09-30 13:00'), $schedule->nextSlot($at('2026-09-30 09:00')));
        $this->assertEquals($at('2026-10-01 09:00'), $schedule->nextSlot($at('2026-09-30 18:30')));
    }
}
