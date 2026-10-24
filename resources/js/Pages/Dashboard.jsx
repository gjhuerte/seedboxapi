import { Head, router, useForm, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';

/* ------------------------------------------------------------------ */
/* Copy                                                                */
/* ------------------------------------------------------------------ */

// Opening statement per state: a bright leading phrase and a grey follow-up.
const HEADLINES = {
    updated: ['Seedbox IP registered.', 'MAM accepted the new address.'],
    unchanged: ['Seedbox IP is current.', 'MAM has this address.'],
    waiting: ['Add your mam_id.', 'Updates start once it is saved.'],
    throttled: ['Change pending.', 'MAM limits IP changes; it retries next check.'],
    rejected: ['MAM rejected the session.', 'Check the mam_id below.'],
    unreachable: ['MAM is unreachable.', 'Check the VPN connection in gluetun.'],
    none: ['Ready.', 'The first check runs within a minute.'],
};

const STATE_LABELS = {
    updated: 'Updated',
    unchanged: 'No change',
    waiting: 'No mam_id',
    throttled: 'Too recent',
    rejected: 'Rejected',
    unreachable: 'Unreachable',
};

const TRIGGER_LABELS = { schedule: 'Scheduled', manual: 'Manual', cookie: 'New mam_id' };

const OK_STATES = ['updated', 'unchanged'];

/* ------------------------------------------------------------------ */
/* Time helpers                                                        */
/* ------------------------------------------------------------------ */

const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

function relative(iso, now) {
    if (!iso) return '—';
    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);
    const abs = Math.abs(seconds);
    if (abs < 45) return seconds <= 0 ? 'just now' : 'in a moment';
    if (abs < 3600) return rtf.format(Math.round(seconds / 60), 'minute');
    if (abs < 86400) return rtf.format(Math.round(seconds / 3600), 'hour');
    return rtf.format(Math.round(seconds / 86400), 'day');
}

function absolute(iso) {
    return iso ? new Date(iso).toLocaleString() : '';
}

function clock(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return `${d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })} ${d.toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    })}`;
}

function duration(seconds) {
    if (seconds % 3600 === 0) return `${seconds / 3600} h`;
    if (seconds % 60 === 0) return `${seconds / 60} min`;
    return `${seconds} s`;
}

function useNow(intervalMs = 15000) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const id = setInterval(() => setNow(Date.now()), intervalMs);
        return () => clearInterval(id);
    }, [intervalMs]);
    return now;
}

/* ------------------------------------------------------------------ */
/* Small pieces                                                        */
/* ------------------------------------------------------------------ */

function Signal({ on }) {
    return (
        <span
            aria-hidden="true"
            className={`inline-block size-1.5 shrink-0 rounded-full ${on ? 'bg-live' : 'bg-hairline-2'}`}
        />
    );
}

function Label({ children, className = '' }) {
    return <span className={`font-mono text-[11px] tracking-[0.08em] text-meta uppercase ${className}`}>{children}</span>;
}

const pill =
    'inline-flex h-10 items-center justify-center gap-2 rounded-pill px-5 text-sm transition-dp disabled:cursor-not-allowed disabled:opacity-50';

function PrimaryButton({ className = '', ...props }) {
    return <button className={`${pill} bg-bright text-ink hover:bg-white ${className}`} {...props} />;
}

function SecondaryButton({ className = '', ...props }) {
    return (
        <button
            className={`${pill} border border-hairline-2 text-bright hover:border-dim hover:bg-panel ${className}`}
            {...props}
        />
    );
}

/* ------------------------------------------------------------------ */
/* mam_id panel                                                        */
/* ------------------------------------------------------------------ */

function MamIdPanel({ mamId, schedule }) {
    const { flash } = usePage();
    const inputId = useId();
    const helpId = useId();
    const errorId = useId();
    const [syncing, setSyncing] = useState(false);
    const form = useForm({ mam_id: '' });

    const save = (event) => {
        event.preventDefault();
        form.put('/mam-id', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const syncNow = () =>
        router.post('/sync', {}, {
            preserveScroll: true,
            onStart: () => setSyncing(true),
            onFinish: () => setSyncing(false),
        });

    const remove = () => {
        if (window.confirm('Remove the stored mam_id? Automatic updates stop until you add a new one.')) {
            router.delete('/mam-id', { preserveScroll: true });
        }
    };

    const busy = form.processing || syncing;

    return (
        <section
            aria-labelledby={`${inputId}-title`}
            className="rounded-panel border border-hairline bg-gradient-to-b from-[#151515] to-panel p-6 sm:p-8"
        >
            <div className="flex items-center justify-between gap-4">
                <h2 id={`${inputId}-title`} className="font-mono text-sm text-bright">
                    mam_id
                </h2>
                <span className="flex items-center gap-2">
                    <Signal on={mamId.configured} />
                    <Label>{mamId.configured ? 'Saved' : 'Not set'}</Label>
                    {mamId.configured && <span className="font-mono text-xs text-read">{mamId.masked}</span>}
                </span>
            </div>

            <form onSubmit={save} className="mt-6">
                <label htmlFor={inputId} className="block text-sm text-bright">
                    {mamId.configured ? 'Replace session cookie' : 'Session cookie'}
                </label>
                <input
                    id={inputId}
                    type="password"
                    autoComplete="off"
                    spellCheck={false}
                    value={form.data.mam_id}
                    onChange={(e) => form.setData('mam_id', e.target.value)}
                    aria-describedby={form.errors.mam_id ? `${errorId} ${helpId}` : helpId}
                    aria-invalid={form.errors.mam_id ? true : undefined}
                    placeholder="Paste the mam_id value"
                    className="mt-2 block h-11 w-full rounded-panel border border-hairline-2 bg-ground px-3 font-mono text-sm text-bright placeholder:text-meta transition-dp hover:border-dim focus:border-bright focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-3 focus-visible:outline-bright"
                />
                {form.errors.mam_id && (
                    <p id={errorId} className="mt-2 text-sm text-bright">
                        {form.errors.mam_id}
                    </p>
                )}
                <p id={helpId} className="mt-3 text-sm leading-relaxed text-read">
                    On MyAnonamouse, open Preferences → Security and create a session for the VPN&apos;s IP with{' '}
                    <span className="text-bright">ASN</span> locking and{' '}
                    <span className="text-bright">Allow Session to set Dynamic Seedbox</span> set to Yes. Paste its
                    cookie value here; it is stored encrypted and never shown again.
                </p>

                <div className="mt-6 flex flex-wrap items-center gap-3">
                    <PrimaryButton type="submit" disabled={busy || form.data.mam_id.trim() === ''}>
                        {form.processing ? 'Saving…' : 'Save & update'}
                    </PrimaryButton>
                    <SecondaryButton type="button" onClick={syncNow} disabled={busy || !mamId.configured}>
                        {syncing ? 'Updating…' : 'Update now'}
                    </SecondaryButton>
                    {mamId.configured && (
                        <button
                            type="button"
                            onClick={remove}
                            disabled={busy}
                            className="ml-auto rounded-pill px-2 py-1 text-sm text-meta underline-offset-4 transition-dp hover:text-bright hover:underline disabled:opacity-50"
                        >
                            Remove
                        </button>
                    )}
                </div>
            </form>

            <p role="status" aria-live="polite" className="mt-5 min-h-5 text-sm text-bright">
                {flash?.notice}
            </p>

            <IntervalForm schedule={schedule} />
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Interval                                                            */
/* ------------------------------------------------------------------ */

function IntervalForm({ schedule }) {
    const inputId = useId();
    const errorId = useId();
    const helpId = useId();
    const minutes = Math.ceil(schedule.interval / 60);
    const form = useForm({ minutes: String(minutes) });

    // Follow the server value after a save, a reset or an INTERVAL change.
    useEffect(() => {
        form.setData('minutes', String(minutes));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [minutes]);

    const save = (event) => {
        event.preventDefault();
        form.put('/interval', { preserveScroll: true });
    };

    const reset = () => router.delete('/interval', { preserveScroll: true });

    const unchanged = form.data.minutes === String(minutes);

    return (
        <form onSubmit={save} className="mt-8 border-t border-hairline pt-6">
            <label htmlFor={inputId} className="block text-sm text-bright">
                Check every
            </label>
            <div className="mt-2 flex flex-wrap items-center gap-3">
                <div className="flex h-11 items-center rounded-panel border border-hairline-2 bg-ground transition-dp focus-within:border-bright hover:border-dim">
                    <input
                        id={inputId}
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={1440}
                        step={1}
                        required
                        value={form.data.minutes}
                        onChange={(e) => form.setData('minutes', e.target.value)}
                        aria-describedby={form.errors.minutes ? `${errorId} ${helpId}` : helpId}
                        aria-invalid={form.errors.minutes ? true : undefined}
                        className="h-full w-20 bg-transparent px-3 font-mono text-sm text-bright tabular-nums focus:outline-none"
                    />
                    <span className="pr-3 text-sm text-meta">min</span>
                </div>
                <SecondaryButton type="submit" disabled={form.processing || unchanged}>
                    {form.processing ? 'Saving…' : 'Save interval'}
                </SecondaryButton>
                {schedule.custom_interval && (
                    <button
                        type="button"
                        onClick={reset}
                        className="rounded-pill px-2 py-1 text-sm text-meta underline-offset-4 transition-dp hover:text-bright hover:underline"
                    >
                        Use INTERVAL ({duration(schedule.default_interval)})
                    </button>
                )}
            </div>
            {form.errors.minutes && (
                <p id={errorId} className="mt-2 text-sm text-bright">
                    {form.errors.minutes}
                </p>
            )}
            <p id={helpId} className="mt-3 text-sm leading-relaxed text-read">
                {schedule.custom_interval
                    ? 'Set here. Changing INTERVAL in your compose file replaces it on the next start.'
                    : 'From the INTERVAL environment variable.'}{' '}
                {!schedule.enabled && 'Automatic updates are off (MAM_AUTO_SYNC=false).'}
            </p>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/* Facts row                                                           */
/* ------------------------------------------------------------------ */

function Facts({ latest, schedule, now }) {
    const facts = [
        { label: 'Seedbox IP', value: latest?.ip ?? '—', mono: true },
        {
            label: 'Network',
            value: latest?.asn ? `AS${latest.asn}` : '—',
            note: latest?.as_org,
            mono: true,
        },
        { label: 'Last check', value: relative(latest?.at, now), note: absolute(latest?.at) },
        {
            label: 'Next check',
            value: schedule.enabled ? relative(schedule.next_at, now) : 'Automatic updates off',
            note: schedule.enabled ? `Every ${duration(schedule.interval)}` : 'MAM_AUTO_SYNC=false',
        },
    ];

    return (
        <dl className="mt-16 grid grid-cols-2 border-t border-hairline md:mt-24 lg:grid-cols-4">
            {facts.map((fact, i) => (
                <div
                    key={fact.label}
                    className={`border-b border-hairline py-5 pr-4 lg:border-b-0 ${i % 2 === 1 ? 'pl-4' : ''} ${
                        i > 0 ? 'lg:border-l lg:pl-6' : ''
                    } ${i % 2 === 1 ? 'border-l' : ''}`}
                >
                    <dt>
                        <Label>{fact.label}</Label>
                    </dt>
                    <dd className={`mt-2 text-lg break-words text-bright ${fact.mono ? 'font-mono tabular-nums' : ''}`}>
                        {fact.value}
                    </dd>
                    {fact.note && <dd className="mt-1 truncate text-sm text-meta">{fact.note}</dd>}
                </div>
            ))}
        </dl>
    );
}

/* ------------------------------------------------------------------ */
/* History: Activity + Logs                                            */
/* ------------------------------------------------------------------ */

function Tabs({ tabs, active, onChange, idPrefix }) {
    const refs = useRef({});

    const onKeyDown = (event) => {
        const keys = tabs.map((t) => t.key);
        const index = keys.indexOf(active);
        let next = null;
        if (event.key === 'ArrowRight') next = keys[(index + 1) % keys.length];
        if (event.key === 'ArrowLeft') next = keys[(index - 1 + keys.length) % keys.length];
        if (event.key === 'Home') next = keys[0];
        if (event.key === 'End') next = keys[keys.length - 1];
        if (next) {
            event.preventDefault();
            onChange(next);
            refs.current[next]?.focus();
        }
    };

    return (
        <div role="tablist" aria-label="History" className="flex gap-2" onKeyDown={onKeyDown}>
            {tabs.map((tab) => {
                const selected = tab.key === active;
                return (
                    <button
                        key={tab.key}
                        ref={(el) => (refs.current[tab.key] = el)}
                        role="tab"
                        id={`${idPrefix}-tab-${tab.key}`}
                        aria-selected={selected}
                        aria-controls={`${idPrefix}-panel-${tab.key}`}
                        tabIndex={selected ? 0 : -1}
                        onClick={() => onChange(tab.key)}
                        className={`inline-flex h-9 items-center gap-2 rounded-pill border px-4 text-sm transition-dp ${
                            selected
                                ? 'border-hairline-2 bg-panel text-bright'
                                : 'border-transparent text-read hover:text-bright'
                        }`}
                    >
                        {selected && <Signal on />}
                        {tab.label}
                        <span className="font-mono text-xs text-meta">{tab.count}</span>
                    </button>
                );
            })}
        </div>
    );
}

function ActivityList({ contacts, now }) {
    if (contacts.length === 0) {
        return <p className="py-10 text-read">No contact with MAM yet. The first check runs within a minute.</p>;
    }

    return (
        <ol className="border-t border-hairline">
            {contacts.map((c) => (
                <li
                    key={c.id}
                    className="grid grid-cols-1 gap-1 border-b border-hairline py-4 sm:grid-cols-[10rem_9rem_1fr] sm:gap-6 md:grid-cols-[10rem_9rem_11rem_1fr]"
                >
                    <time dateTime={c.at} title={absolute(c.at)} className="font-mono text-sm text-meta tabular-nums">
                        {relative(c.at, now)}
                    </time>
                    <span className="flex items-center gap-2 text-sm text-bright">
                        <Signal on={OK_STATES.includes(c.state)} />
                        {STATE_LABELS[c.state] ?? c.state}
                    </span>
                    <span className="font-mono text-sm text-bright tabular-nums sm:col-start-3 md:col-start-auto">
                        {c.ip ?? '—'}
                        {c.asn && <span className="ml-2 text-meta">AS{c.asn}</span>}
                    </span>
                    <span className="text-sm leading-relaxed text-read sm:col-start-3 md:col-start-auto">
                        {c.message}
                        {c.http_status && c.http_status !== 200 && (
                            <span className="ml-2 font-mono text-meta">HTTP {c.http_status}</span>
                        )}
                        <span className="ml-2 font-mono text-xs text-meta">· {TRIGGER_LABELS[c.trigger] ?? c.trigger}</span>
                    </span>
                </li>
            ))}
        </ol>
    );
}

const LEVEL_STYLES = {
    emergency: 'text-bright',
    alert: 'text-bright',
    critical: 'text-bright',
    error: 'text-bright',
    warning: 'text-bright',
    notice: 'text-read',
    info: 'text-read',
    debug: 'text-meta',
};

function LogList({ logs }) {
    if (logs.length === 0) {
        return (
            <p className="py-10 text-read">
                No log entries yet. Set <span className="font-mono text-bright">DEBUG=1</span> to log every check.
            </p>
        );
    }

    return (
        <ol className="border-t border-hairline font-mono text-[13px] leading-relaxed">
            {logs.map((entry, i) => (
                <li
                    key={`${entry.at}-${i}`}
                    className="grid grid-cols-1 gap-1 border-b border-hairline py-3 sm:grid-cols-[10rem_5.5rem_1fr] sm:gap-6"
                >
                    <time dateTime={entry.at} title={absolute(entry.at)} className="text-meta tabular-nums">
                        {clock(entry.at)}
                    </time>
                    <span className={`uppercase ${LEVEL_STYLES[entry.level] ?? 'text-read'}`}>{entry.level}</span>
                    <span className="break-words whitespace-pre-wrap text-read">{entry.message}</span>
                </li>
            ))}
        </ol>
    );
}

function History({ contacts, logs, now }) {
    const idPrefix = useId();
    const [tab, setTab] = useState(() => {
        try {
            return localStorage.getItem('history-tab') === 'logs' ? 'logs' : 'activity';
        } catch {
            return 'activity';
        }
    });

    const change = (key) => {
        setTab(key);
        try {
            localStorage.setItem('history-tab', key);
        } catch {
            /* storage unavailable */
        }
    };

    return (
        <section aria-labelledby={`${idPrefix}-title`} className="shell py-20 md:py-28">
            <div className="flex flex-wrap items-end justify-between gap-6">
                <div>
                    <h2 id={`${idPrefix}-title`} className="text-3xl tracking-[-0.02em] text-bright md:text-4xl">
                        History
                    </h2>
                    <p className="mt-3 max-w-xl text-read">
                        Every request to MAM, and the app&apos;s own log. Refreshes every 15 seconds.
                    </p>
                </div>
                <Tabs
                    idPrefix={idPrefix}
                    active={tab}
                    onChange={change}
                    tabs={[
                        { key: 'activity', label: 'Activity', count: contacts.length },
                        { key: 'logs', label: 'Logs', count: logs.length },
                    ]}
                />
            </div>

            <div
                role="tabpanel"
                id={`${idPrefix}-panel-${tab}`}
                aria-labelledby={`${idPrefix}-tab-${tab}`}
                className="mt-10"
            >
                {tab === 'activity' ? <ActivityList contacts={contacts} now={now} /> : <LogList logs={logs} />}
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Page                                                                */
/* ------------------------------------------------------------------ */

export default function Dashboard({ mamId, latest, contacts, logs, schedule }) {
    const now = useNow();
    usePoll(15000, { only: ['mamId', 'latest', 'contacts', 'logs', 'schedule'] });

    const state = latest?.state ?? 'none';
    const headline = !mamId.configured ? 'waiting' : state === 'waiting' ? 'none' : state;
    const [lead, rest] = HEADLINES[headline] ?? HEADLINES.none;
    const live = schedule.enabled && mamId.configured && OK_STATES.includes(state);

    return (
        <>
            <Head title={latest?.ip ?? 'Dashboard'} />

            <header className="border-b border-hairline">
                <div className="shell flex h-16 items-center justify-between gap-4">
                    <span className="font-mono text-sm text-bright">
                        seedboxapi<span className="hidden text-meta sm:inline"> / MyAnonamouse</span>
                    </span>
                    <span className="flex items-center gap-2 whitespace-nowrap">
                        <Signal on={live} />
                        <Label>
                            {schedule.enabled ? `Auto-update · ${duration(schedule.interval)}` : 'Auto-update off'}
                        </Label>
                    </span>
                </div>
            </header>

            <main>
                <section className="relative overflow-hidden">
                    {/* Static pool of white light behind the opening. */}
                    <div
                        aria-hidden="true"
                        className="pointer-events-none absolute inset-0"
                        style={{
                            background:
                                'radial-gradient(60% 70% at 78% 18%, rgba(255,255,255,0.09), transparent 70%), radial-gradient(40% 50% at 10% 0%, rgba(255,255,255,0.04), transparent 70%)',
                        }}
                    />
                    <div className="shell relative pt-16 pb-0 md:pt-24">
                        <div className="grid grid-cols-12 gap-y-12 lg:gap-x-6">
                            <div className="col-span-12 lg:col-span-7">
                                <Label>Dynamic seedbox IP</Label>
                                <h1 className="mt-6 text-[clamp(44px,7.4vw,104px)] leading-[1.0] font-normal tracking-[-0.045em] text-balance">
                                    <span className="text-bright">{lead}</span>{' '}
                                    <span className="text-dim">{rest}</span>
                                </h1>
                                <p className="mt-8 max-w-xl text-lg leading-relaxed text-read">
                                    Runs inside gluetun&apos;s network, so MAM sees the VPN address. Every{' '}
                                    {duration(schedule.interval)} it reports that address to MyAnonamouse and keeps the
                                    session cookie MAM rotates.
                                </p>
                            </div>
                            <div className="col-span-12 md:col-span-8 lg:col-span-5 lg:pt-10">
                                <MamIdPanel mamId={mamId} schedule={schedule} />
                            </div>
                        </div>

                        <Facts latest={latest} schedule={schedule} now={now} />
                    </div>
                </section>

                <History contacts={contacts} logs={logs} now={now} />
            </main>

            <footer className="border-t border-hairline">
                <div className="shell grid grid-cols-1 gap-8 py-10 sm:grid-cols-3">
                    <p className="font-mono text-sm text-meta">seedboxapi</p>
                    <div>
                        <Label>MyAnonamouse</Label>
                        <ul className="mt-3 space-y-2 text-sm">
                            <li>
                                <a
                                    href="https://www.myanonamouse.net/preferences/index.php?view=security"
                                    target="_blank"
                                    rel="noreferrer noopener"
                                    className="text-read underline-offset-4 transition-dp hover:text-bright hover:underline"
                                >
                                    Security sessions
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div>
                        <Label>Container</Label>
                        <ul className="mt-3 space-y-2 text-sm">
                            <li>
                                <a
                                    href="/up"
                                    className="text-read underline-offset-4 transition-dp hover:text-bright hover:underline"
                                >
                                    Health check
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </footer>
        </>
    );
}
