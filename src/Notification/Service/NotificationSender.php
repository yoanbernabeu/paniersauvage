<?php

declare(strict_types=1);

namespace App\Notification\Service;

use App\Customer\Entity\Customer;
use App\Notification\Enum\NotificationChannel;
use App\Notification\Enum\NotificationStatus;
use App\Notification\Enum\NotificationType;
use App\Notification\Repository\NotificationLogRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends one e-mail and traces it in a NotificationLog. It never throws: a notification problem must not
 * break the business action that triggered it.
 *
 * The log row is written directly through the connection (in its own transaction, a savepoint when the
 * caller has one open): sending a notification never flushes the caller's unit of work. A message sent
 * `once` carries a deduplication key under a unique constraint, so two concurrent sends cannot both go.
 */
final readonly class NotificationSender
{
    /** Header carrying the NotificationLog id, so that the delivery outcome can be reported on the log. */
    public const string LOG_HEADER = 'X-Notification-Log';

    public function __construct(
        private MailerInterface $mailer,
        private Connection $connection,
        private NotificationLogRepository $logs,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Key shared by every successful send of the same message, to the same address, about the same object.
     */
    public static function dedupKey(NotificationType $type, string $relatedType, int $relatedId, string $recipient): string
    {
        return hash('sha256', implode('|', [$type->value, $relatedType, $relatedId, mb_strtolower($recipient)]));
    }

    /**
     * @param \Closure(): Email $compose builds the message; runs inside the error guard
     * @param bool              $once    skip when the same message was already sent to this address for this object
     *
     * @return bool true when the message was handed to the mailer
     */
    public function send(
        NotificationType $type,
        Address $to,
        string $relatedType,
        ?int $relatedId,
        \Closure $compose,
        ?Customer $customer = null,
        bool $once = false,
    ): bool {
        $logId = null;

        try {
            if ($once && null !== $relatedId && $this->logs->wasSent($type, $relatedType, $relatedId, $to->getAddress())) {
                return false;
            }

            // A deleted customer account is anonymised with such an address: never write to it.
            $deleted = Customer::isAnonymizedEmail($to->getAddress());
            $dedupKey = $once && null !== $relatedId && !$deleted ? self::dedupKey($type, $relatedType, $relatedId, $to->getAddress()) : null;

            $logId = $this->insertLog([
                'type' => $type->value,
                'customer_id' => $customer?->getId(),
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'status' => ($deleted ? NotificationStatus::Skipped : NotificationStatus::Sent)->value,
                'channel' => NotificationChannel::Email->value,
                'recipient' => $to->getAddress(),
                'sent_at' => $this->clock->now(),
                'dedup_key' => $dedupKey,
            ]);

            if (null === $logId || $deleted) {
                return false;
            }

            $email = $compose()->to($to);
            $email->getHeaders()->addTextHeader(self::LOG_HEADER, (string) $logId);
            $this->mailer->send($email);

            return true;
        } catch (\Throwable $exception) {
            $this->logger->error('Notification "{type}" could not be sent: {message}', [
                'type' => $type->value,
                'message' => $exception->getMessage(),
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'exception' => $exception,
            ]);
            $this->markFailed($logId);

            return false;
        }
    }

    /**
     * Runs the preparation of a batch of notifications (queries, loops) without ever throwing.
     *
     * @param \Closure(): void $work
     */
    public function guard(NotificationType $type, \Closure $work): void
    {
        try {
            $work();
        } catch (\Throwable $exception) {
            $this->logger->error('Notifications "{type}" could not be prepared: {message}', [
                'type' => $type->value,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }

    /**
     * Reports the delivery outcome on the log (only that row is written). A failed message frees its
     * deduplication key, so that it can be sent again.
     */
    public function reportStatus(int $logId, NotificationStatus $status): void
    {
        $data = ['status' => $status->value];
        if (NotificationStatus::Sent !== $status) {
            $data['dedup_key'] = null;
        }

        $this->connection->update('notification_log', $data, ['id' => $logId]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return int|null the id of the log, null when the same message was sent meanwhile (deduplication key taken)
     */
    private function insertLog(array $row): ?int
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->insert('notification_log', $row, ['sent_at' => Types::DATETIME_IMMUTABLE]);
            $id = (int) $this->connection->lastInsertId();
            $this->connection->commit();

            return $id;
        } catch (UniqueConstraintViolationException) {
            $this->connection->rollBack();

            return null;
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }
    }

    private function markFailed(?int $logId): void
    {
        if (null === $logId) {
            return;
        }

        try {
            $this->reportStatus($logId, NotificationStatus::Failed);
        } catch (\Throwable $exception) {
            $this->logger->error('Notification log could not be marked as failed: {message}', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
