<?php

declare(strict_types=1);

namespace App\Notification\EventListener;

use App\Notification\Enum\NotificationStatus;
use App\Notification\Service\NotificationSender;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * E-mails leave through Messenger: the real delivery happens later, in a worker. This listener reports
 * the outcome on the NotificationLog (failed, or sent again after a successful retry).
 */
final readonly class MailDeliveryListener
{
    public function __construct(
        private NotificationSender $sender,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onFailed(FailedMessageEvent $event): void
    {
        $this->report($event->getMessage(), NotificationStatus::Failed);
    }

    #[AsEventListener]
    public function onSent(SentMessageEvent $event): void
    {
        $this->report($event->getMessage()->getOriginalMessage(), NotificationStatus::Sent);
    }

    private function report(RawMessage $message, NotificationStatus $status): void
    {
        if (!$message instanceof Message) {
            return;
        }

        $logId = $message->getHeaders()->get(NotificationSender::LOG_HEADER)?->getBodyAsString();
        if (null === $logId || !ctype_digit($logId)) {
            return;
        }

        try {
            // Only the log row is written: the worker's unit of work is left alone.
            $this->sender->reportStatus((int) $logId, $status);
        } catch (\Throwable $exception) {
            $this->logger->error('Notification log #{id} could not be updated: {message}', [
                'id' => $logId,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
