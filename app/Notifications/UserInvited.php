<?php

namespace App\Notifications;

use App\Enums\UserRole;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when an admin adds a user via the "Add a user" form
 * (UserAccessController::store()) - genuinely queued (ShouldQueue), not
 * dispatchSync()'d like AdsAccountSyncer's "run immediately" case: an
 * invitation email isn't a correctness concern blocking the admin's request
 * the way live dashboard data was, so it follows Laravel's normal
 * queued-notification pattern. Like the nightly ads-accounts:sync-all job,
 * this still needs something actually consuming the queue (`composer dev`'s
 * queue:listen, or a manual `queue:work`) to be delivered - MAIL_MAILER=log
 * in this dev environment means "delivered" means written to
 * storage/logs/laravel.log, not a real inbox, until a real mailer is
 * configured.
 */
class UserInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly UserRole $role) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You now have access to the ChatGPT Ads Industry Dashboard')
            ->greeting('Hi,')
            ->line("You've been granted **{$this->role->value}** access to the ChatGPT Ads Industry Dashboard.")
            ->line('Sign in with your Google account to get started.')
            ->action('Sign in', url('/login'))
            ->line('If you weren\'t expecting this, you can ignore this email.');
    }
}
