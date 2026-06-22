<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /**
     * Note: this app's settings (poll interval, LLM backend, API keys) live
     * in .env / config files rather than a DB-backed settings table, since
     * model/backend selection affects which service container bindings are
     * resolved (see AppServiceProvider). This page is read-only/informational
     * — editing requires updating .env and restarting queue workers.
     * Swap for a DB-backed settings table if you want in-UI editing.
     */
    public function index(): Response
    {
        return Inertia::render('Settings/Index', [
            'config' => [
                'triage_backend' => config('triage.backend'),
                'triage_model' => config('triage.'.config('triage.backend').'.model'),
                'embedding_backend' => config('embedding.backend'),
                'embedding_model' => config('embedding.'.config('embedding.backend').'.model'),
                'poll_cron' => config('gmail.poll_cron'),
                'default_confidence_threshold' => config('triage.default_confidence_threshold'),
                'presidio_score_threshold' => config('presidio.score_threshold'),
            ],
        ]);
    }
}
