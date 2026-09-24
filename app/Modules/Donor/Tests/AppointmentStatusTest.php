<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Modules\Donor\Domain\AppointmentStatus;
use Tests\TestCase;

final class AppointmentStatusTest extends TestCase
{
    public function test_booked_can_transition_to_arrived_no_show_or_cancelled(): void
    {
        $this->assertTrue(AppointmentStatus::BOOKED->canTransitionTo(AppointmentStatus::ARRIVED));
        $this->assertTrue(AppointmentStatus::BOOKED->canTransitionTo(AppointmentStatus::NO_SHOW));
        $this->assertTrue(AppointmentStatus::BOOKED->canTransitionTo(AppointmentStatus::CANCELLED));
    }

    public function test_booked_cannot_transition_to_screened_or_completed(): void
    {
        $this->assertFalse(AppointmentStatus::BOOKED->canTransitionTo(AppointmentStatus::SCREENED));
        $this->assertFalse(AppointmentStatus::BOOKED->canTransitionTo(AppointmentStatus::COMPLETED));
    }

    public function test_arrived_can_transition_to_screened_or_cancelled_only(): void
    {
        $this->assertTrue(AppointmentStatus::ARRIVED->canTransitionTo(AppointmentStatus::SCREENED));
        $this->assertTrue(AppointmentStatus::ARRIVED->canTransitionTo(AppointmentStatus::CANCELLED));
        $this->assertFalse(AppointmentStatus::ARRIVED->canTransitionTo(AppointmentStatus::COMPLETED));
        $this->assertFalse(AppointmentStatus::ARRIVED->canTransitionTo(AppointmentStatus::BOOKED));
    }

    public function test_screened_can_transition_to_completed_or_cancelled_only(): void
    {
        $this->assertTrue(AppointmentStatus::SCREENED->canTransitionTo(AppointmentStatus::COMPLETED));
        $this->assertTrue(AppointmentStatus::SCREENED->canTransitionTo(AppointmentStatus::CANCELLED));
        $this->assertFalse(AppointmentStatus::SCREENED->canTransitionTo(AppointmentStatus::ARRIVED));
    }

    public function test_completed_no_show_and_cancelled_are_terminal(): void
    {
        $this->assertTrue(AppointmentStatus::COMPLETED->isTerminal());
        $this->assertTrue(AppointmentStatus::NO_SHOW->isTerminal());
        $this->assertTrue(AppointmentStatus::CANCELLED->isTerminal());

        $this->assertSame([], AppointmentStatus::COMPLETED->allowedNext());
        $this->assertSame([], AppointmentStatus::NO_SHOW->allowedNext());
        $this->assertSame([], AppointmentStatus::CANCELLED->allowedNext());
    }

    public function test_booked_and_arrived_and_screened_are_not_terminal(): void
    {
        $this->assertFalse(AppointmentStatus::BOOKED->isTerminal());
        $this->assertFalse(AppointmentStatus::ARRIVED->isTerminal());
        $this->assertFalse(AppointmentStatus::SCREENED->isTerminal());
    }

    public function test_completed_to_arrived_is_illegal(): void
    {
        $this->assertFalse(AppointmentStatus::COMPLETED->canTransitionTo(AppointmentStatus::ARRIVED));
    }

    public function test_no_show_to_arrived_is_illegal(): void
    {
        $this->assertFalse(AppointmentStatus::NO_SHOW->canTransitionTo(AppointmentStatus::ARRIVED));
    }
}
