<?php

namespace App\Http\Controllers;

use App\Models\SenderStat;
use Inertia\Inertia;
use Inertia\Response;

class SenderStatController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Senders/Index', [
            'senders' => SenderStat::orderByDesc('email_count')->paginate(25),
        ]);
    }

    public function show(SenderStat $senderStat): Response
    {
        return Inertia::render('Senders/Show', [
            'sender' => $senderStat,
        ]);
    }
}
