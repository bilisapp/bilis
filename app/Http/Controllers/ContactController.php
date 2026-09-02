<?php

namespace App\Http\Controllers;

use App\Enums\ContactTopic;
use App\Http\Requests\StoreContactMessageRequest;
use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

/**
 * The public contact form.
 *
 * Blade, no JavaScript, and reachable while logged out: it is the only way to
 * ask for more than the Free plan, so it has to work in the browser of
 * someone who has not signed up yet.
 */
class ContactController extends Controller
{
    /**
     * Show the form, pre-filled from `?topic=` and the signed-in account.
     */
    public function show(Request $request): View
    {
        $user = $request->user();

        return view('marketing.contact', [
            'topics' => ContactTopic::cases(),
            'selectedTopic' => ContactTopic::fromQuery($request->query('topic') !== null ? (string) $request->query('topic') : null),
            'prefillName' => $user?->name,
            'prefillEmail' => $user?->email,
            'sent' => (bool) $request->session()->get('contact.sent', false),
        ]);
    }

    /**
     * Record a message and notify us about it.
     */
    public function store(StoreContactMessageRequest $request): RedirectResponse
    {
        /*
         * The honeypot. A field no person can see and no person fills in; a
         * bot filling every input trips it. The response is indistinguishable
         * from a successful send on purpose — telling a script it was caught
         * is telling it what to change.
         */
        if (trim((string) $request->input('website')) !== '') {
            Log::info('Contact form honeypot tripped.', ['ip' => $request->ip()]);

            return $this->sent();
        }

        $user = $request->user();

        $message = ContactMessage::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'topic' => $request->validated('topic'),
            'message' => $request->validated('message'),
            'user_id' => $user?->id,
            'team_id' => $user?->currentTeam?->id,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        /*
         * The row above is the safety net. A mailer that is not configured
         * yet, or a queue that is not running, must not turn someone's
         * question into a 500 — it is reported and the sender still gets the
         * thank-you they earned.
         */
        try {
            Mail::to(config('legal.contact.general'))->send(new ContactMessageReceived($message));
        } catch (Throwable $exception) {
            report($exception);
        }

        return $this->sent();
    }

    /**
     * Back to the form in its thank-you state.
     */
    private function sent(): RedirectResponse
    {
        return to_route('contact.show')->with('contact.sent', true);
    }
}
