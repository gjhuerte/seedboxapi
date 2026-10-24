<?php

namespace App\Http\Controllers;

use App\Actions\SyncSeedboxIp;
use App\Models\Contact;
use App\Services\LogReader;
use App\Services\Mam\MamCookie;
use App\Services\SyncInterval;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(MamCookie $cookie, SyncSeedboxIp $sync, LogReader $logs, SyncInterval $interval): Response
    {
        $cookie->syncFromEnvironment();

        $contacts = Contact::query()->latest('id')->limit(20)->get();

        return Inertia::render('Dashboard', [
            'mamId' => [
                'configured' => $cookie->get() !== null,
                'masked' => $cookie->masked(),
            ],
            'latest' => $contacts->first()?->toDashboard(),
            'contacts' => $contacts->map->toDashboard()->values(),
            'logs' => $logs->latest(),
            'schedule' => [
                'enabled' => (bool) config('seedbox.auto_sync'),
                'interval' => $interval->seconds(),
                'default_interval' => $interval->environment(),
                'custom_interval' => $interval->isCustom(),
                'next_at' => $sync->nextContactAt()?->toIso8601String(),
            ],
        ]);
    }

    public function update(Request $request, MamCookie $cookie, SyncSeedboxIp $sync): RedirectResponse
    {
        $validated = $request->validate([
            'mam_id' => ['required', 'string', 'max:2048'],
        ]);

        $value = MamCookie::clean($validated['mam_id']);

        if ($value === '' || preg_match('/\s/', $value)) {
            return back()->withErrors(['mam_id' => 'Paste only the mam_id value, without spaces.']);
        }

        $cookie->set($value);
        $contact = $sync->run('cookie');

        Inertia::flash('notice', $this->describe($contact, 'mam_id saved.'));

        return back();
    }

    public function destroy(MamCookie $cookie): RedirectResponse
    {
        $cookie->clear();

        Inertia::flash('notice', 'mam_id removed. Updates are paused until you add one.');

        return back();
    }

    public function interval(Request $request, SyncInterval $interval): RedirectResponse
    {
        $validated = $request->validate([
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ], [
            'minutes.*' => 'Enter a whole number of minutes between 1 and 1440.',
        ]);

        $interval->set((int) $validated['minutes'] * 60);

        Inertia::flash('notice', "Updates now run every {$validated['minutes']} min.");

        return back();
    }

    public function resetInterval(SyncInterval $interval): RedirectResponse
    {
        $interval->reset();

        Inertia::flash('notice', 'Interval reset to INTERVAL from the environment.');

        return back();
    }

    public function sync(SyncSeedboxIp $sync): RedirectResponse
    {
        Inertia::flash('notice', $this->describe($sync->run('manual')));

        return back();
    }

    private function describe(Contact $contact, string $prefix = ''): string
    {
        $outcome = match ($contact->state()) {
            'updated' => "MAM registered {$contact->ip}.",
            'unchanged' => "MAM already has {$contact->ip}.",
            'waiting' => 'Add a mam_id to start updating.',
            'throttled' => 'MAM says the last change was too recent. It will retry automatically.',
            'unreachable' => 'MAM could not be reached: '.$contact->message,
            default => 'MAM rejected the update: '.$contact->message,
        };

        return trim("{$prefix} {$outcome}");
    }
}
