<?php

declare(strict_types=1);

namespace Larena\Core\Enums;

/**
 * The host capabilities a profile describes.
 *
 * The set is closed: a package asking about something not in this list is asking
 * about something the platform has not agreed to describe, and gets a refusal
 * rather than a guess.
 */
enum EnvironmentCapability: string
{
    case QueueWorker = 'queue_worker';
    case Scheduler = 'scheduler';
    case Redis = 'redis';
    case SearchEngine = 'search_engine';
    case ObjectStorage = 'object_storage';
    case ImageProcessing = 'image_processing';
    case MailTransport = 'mail_transport';
    case OutboundHttp = 'outbound_http';
    case WritableStoragePath = 'writable_storage_path';
    case Opcache = 'opcache';
    case ProcessControl = 'process_control';

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
