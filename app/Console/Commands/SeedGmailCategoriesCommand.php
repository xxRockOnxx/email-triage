<?php

namespace App\Console\Commands;

use App\Services\Category\GmailCategorySeeder;
use Illuminate\Console\Command;

/**
 * php artisan categories:seed-gmail
 * Run once after migrating, before the first poll, so the LLM has a
 * baseline taxonomy to match against from the very first triage.
 */
class SeedGmailCategoriesCommand extends Command
{
    protected $signature = 'categories:seed-gmail';

    protected $description = "Seed the categories table from Gmail's built-in category taxonomy";

    public function handle(GmailCategorySeeder $seeder): int
    {
        $seeder->seed();

        $this->info('Gmail-derived categories seeded.');

        return self::SUCCESS;
    }
}
