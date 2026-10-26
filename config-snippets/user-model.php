<?php
// MERGE INTO: app/Models/User.php  (Migration Guide 2.3 + the author columns)

protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'preferences' => 'json',              // Statamic (required for DB users)
        'two_factor_confirmed_at' => 'datetime', // Statamic (required for DB users)
        'socials' => 'json',                  // author profile grid
        'is_guest' => 'boolean',
    ];
}

// Statamic's password-reset mail (optional, needs MAIL_* configured):
public function sendPasswordResetNotification($token)
{
    $this->notify(new \Statamic\Notifications\PasswordReset($token));
}
