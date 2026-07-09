<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SuperAdminPasswordResetCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $username,
        public string $code,
        public int $expiresInMinutes,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Your Form Builder password reset code')
            ->view('emails.super-admin-reset-code');
    }
}
