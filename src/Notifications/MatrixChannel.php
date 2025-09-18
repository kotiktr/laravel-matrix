<?php

namespace Kotiktr\LaravelMatrix\Notifications;

use Illuminate\Notifications\Notification;
use Kotiktr\LaravelMatrix\Bot\MatrixBot;

class MatrixChannel
{
    protected MatrixBot $bot;

    public function __construct(MatrixBot $bot)
    {
        $this->bot = $bot;
    }

    public function send($notifiable, Notification $notification)
    {
        if (method_exists($notification, 'toMatrix')) {
            $data = $notification->toMatrix($notifiable);
            $room = $notifiable->routeNotificationFor('matrix') ?? ($data['room'] ?? null);
            $message = is_string($data) ? $data : ($data['message'] ?? null);

            if ($room && $message) {
                $this->bot->send($room, $message);
            }
        }
    }
}
