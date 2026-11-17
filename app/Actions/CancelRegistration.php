<?php

namespace App\Actions;

use App\Enums\RegistrationStatus;
use App\Models\Registration;

class CancelRegistration
{
    /**
     * Soft cancel: the row is kept because analytics needs the history.
     */
    public function handle(Registration $registration): Registration
    {
        if ($registration->status === RegistrationStatus::Cancelled) {
            return $registration;
        }

        $registration->update([
            'status' => RegistrationStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        return $registration->refresh();
    }
}
