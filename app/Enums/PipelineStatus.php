<?php

namespace App\Enums;

enum PipelineStatus: string
{
    case Pending = 'pending';       // persisted, chain not yet dispatched
    case Processing = 'processing'; // anonymize→triage chain in flight
    case Completed = 'completed';   // anonymize + triage both succeeded
    case Failed = 'failed';         // chain failed permanently (after retries)
}
