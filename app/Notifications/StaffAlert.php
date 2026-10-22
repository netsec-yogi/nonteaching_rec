<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** In-app notice for staff dashboards. Email is sent separately through TemplateMailer. */
class StaffAlert extends Notification
{
    public function __construct(public string $title, public string $message, public ?string $link = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'message' => $this->message, 'link' => $this->link];
    }
}
