<?php

namespace Database\Seeders;

use App\Enums\CategorySource;
use App\Models\Category;
use Illuminate\Database\Seeder;

class DefaultCategorySeeder extends Seeder
{
    /**
     * The baseline category taxonomy seeded for every install.
     *
     * Keyed by name (the table's unique constraint), value is the description.
     * Re-running this seeder keeps the description in sync with this list while
     * preserving each row's id and any user-set confidence_threshold.
     */
    private const DEFAULT_CATEGORIES = [
        'Banking' => 'Bank, credit card, or financial institution correspondence: statements, alerts, fraud notices, transfers.',
        'Receipt/Invoice' => 'Purchase receipts, invoices, and billing statements not from a bank.',
        'Job Opportunity' => 'Recruiter outreach, job applications, interview scheduling, offers.',
        'Marketing' => 'Promotional emails, deals, sales, and offers from companies.',
        'Newsletter' => 'Recurring editorial or informational content you subscribed to.',
        'Social Media' => 'Notifications from social networks and community platforms.',
        'Personal' => 'Direct, individual correspondence from people you know.',
        'Action Required' => 'Requires a direct response or decision from you, not covered by a more specific category.',
        'Calendar/Scheduling' => 'Meeting invites, scheduling requests, and calendar changes.',
        'Shipping/Delivery' => 'Order confirmations, shipping notifications, and delivery updates.',
        'Travel' => 'Flight, hotel, or other travel bookings and itinerary updates.',
        'Account/Security' => 'Login alerts, password resets, two-factor codes, account verification.',
        'Forums/Mailing Lists' => 'Group discussions and mailing list digests you participate in.',
        'Spam/Suspicious' => 'Unsolicited or potentially malicious email.',
        'Onboarding' => 'Welcome messages, setup instructions, getting started guides, and first-time user orientation emails from a service or platform.',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::DEFAULT_CATEGORIES as $name => $description) {
            Category::updateOrCreate(
                ['name' => $name],
                [
                    'description' => $description,
                    'source' => CategorySource::User,
                    'status' => 'active',
                    'is_system_default' => true,
                ],
            );
        }
    }
}
