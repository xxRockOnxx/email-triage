<?php

namespace App\Enums;

enum TriageStatus: string
{
    case AutoFiled = 'auto_filed';     // confidence >= threshold, quietly sorted
    case NeedsReview = 'needs_review'; // confidence < threshold, surfaced in UI
    case Corrected = 'corrected';      // user has overridden this triage result
}
