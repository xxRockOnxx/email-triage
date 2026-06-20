<?php

namespace App\Services\Category;

use App\Enums\CategorySource;
use App\Models\Category;

/**
 * Seeds the categories table from Gmail's own taxonomy (its CATEGORY_* labels
 * plus IMPORTANT/starred as priority signals). This becomes the baseline
 * the LLM matches against; LLM-proposed categories layer on top as refinements.
 */
class GmailCategorySeeder
{
    /**
     * Gmail label id => [name, description]
     */
    private const GMAIL_CATEGORIES = [
        'CATEGORY_PERSONAL' => [
            'name' => 'Personal',
            'description' => 'Direct, individual correspondence from people you know.',
        ],
        'CATEGORY_SOCIAL' => [
            'name' => 'Social',
            'description' => 'Notifications from social networks and community platforms.',
        ],
        'CATEGORY_PROMOTIONS' => [
            'name' => 'Promotional',
            'description' => 'Marketing emails, deals, and offers.',
        ],
        'CATEGORY_UPDATES' => [
            'name' => 'Updates',
            'description' => 'Automated notifications: receipts, confirmations, statements, shipping updates.',
        ],
        'CATEGORY_FORUMS' => [
            'name' => 'Forums',
            'description' => 'Mailing list and online group/forum discussions.',
        ],
    ];

    /**
     * Additional sensible defaults not directly mapped from a Gmail category
     * label, but useful as a starting taxonomy for the LLM to refine into.
     */
    private const ADDITIONAL_DEFAULTS = [
        [
            'name' => 'Action Required',
            'description' => 'Requires a direct response or decision from you.',
        ],
        [
            'name' => 'Receipt/Invoice',
            'description' => 'Purchase receipts, invoices, and billing statements.',
        ],
        [
            'name' => 'Calendar/Scheduling',
            'description' => 'Meeting invites, scheduling requests, and calendar changes.',
        ],
        [
            'name' => 'Spam/Suspicious',
            'description' => 'Unsolicited or potentially malicious email.',
        ],
    ];

    public function seed(): void
    {
        foreach (self::GMAIL_CATEGORIES as $labelId => $meta) {
            Category::firstOrCreate(
                ['name' => $meta['name']],
                [
                    'description' => $meta['description'],
                    'source' => CategorySource::Gmail,
                    'gmail_label_id' => $labelId,
                    'status' => 'active',
                    'is_system_default' => true,
                ]
            );
        }

        foreach (self::ADDITIONAL_DEFAULTS as $meta) {
            Category::firstOrCreate(
                ['name' => $meta['name']],
                [
                    'description' => $meta['description'],
                    'source' => CategorySource::User,
                    'status' => 'active',
                    'is_system_default' => true,
                ]
            );
        }
    }

    /**
     * Map a Gmail label id seen on an inbound message to a Category, if it
     * corresponds to one of the seeded Gmail categories.
     */
    public function categoryForGmailLabel(string $labelId): ?Category
    {
        if (! isset(self::GMAIL_CATEGORIES[$labelId])) {
            return null;
        }

        return Category::where('name', self::GMAIL_CATEGORIES[$labelId]['name'])->first();
    }
}
