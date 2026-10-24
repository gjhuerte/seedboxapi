<?php

namespace App\Console\Commands;

use App\Actions\SyncSeedboxIp;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mam:sync {--if-due : Only contact MAM when INTERVAL has passed since the last contact}')]
#[Description('Update the dynamic seedbox IP on MyAnonamouse')]
class MamSync extends Command
{
    public function handle(SyncSeedboxIp $sync): int
    {
        if ($this->option('if-due') && ! $sync->isDue()) {
            return self::SUCCESS;
        }

        $contact = $sync->run($this->option('if-due') ? 'schedule' : 'manual');

        $this->line(sprintf(
            '%s: %s%s',
            $contact->state(),
            $contact->message ?? '—',
            $contact->ip ? " ({$contact->ip})" : '',
        ));

        return in_array($contact->state(), ['updated', 'unchanged', 'waiting'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }
}
