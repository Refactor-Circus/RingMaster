{{--
    The inbox alarm: plays a sound in this browser when something new needs
    you, and optionally again while it waits. Set up in Settings; stored in
    this browser only. Counts come from <livewire:nav-counter />.
--}}
<script data-navigate-once>
    (() => {
        const KEY = 'ring-master.alarm';
        const LAST = 'ring-master.alarm.last';
        const DEFAULTS = { on: false, sound: 'chime', volume: 0.6, mode: 'new', every: 5, quiet: false, quietFrom: '22:00', quietTo: '07:00' };

        const read = (key, fallback) => { try { return localStorage.getItem(key) ?? fallback } catch (e) { return fallback } };
        const write = (key, value) => { try { localStorage.setItem(key, value) } catch (e) {} };

        let context = null;
        const audio = () => {
            context ??= new (window.AudioContext || window.webkitAudioContext)();
            if (context.state === 'suspended') context.resume();
            return context;
        };

        // Browsers only start audio after you interact with the page once.
        const unlock = () => { try { audio() } catch (e) {} };
        window.addEventListener('pointerdown', unlock, { once: true });
        window.addEventListener('keydown', unlock, { once: true });

        const tone = (ctx, out, { type = 'sine', from, to = from, start, length, peak = 1 }) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = type;
            osc.frequency.setValueAtTime(from, start);
            osc.frequency.linearRampToValueAtTime(to, start + length);
            gain.gain.setValueAtTime(0.0001, start);
            gain.gain.exponentialRampToValueAtTime(peak, start + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + length);
            osc.connect(gain).connect(out);
            osc.start(start);
            osc.stop(start + length + 0.05);
        };

        const SOUNDS = {
            chime: (ctx, out, t) => { tone(ctx, out, { from: 880, start: t, length: 0.6 }); tone(ctx, out, { from: 1320, start: t + 0.18, length: 0.9 }); },
            bell: (ctx, out, t) => { tone(ctx, out, { from: 660, start: t, length: 1.8 }); tone(ctx, out, { from: 1320, start: t, length: 1.2, peak: 0.4 }); tone(ctx, out, { from: 1980, start: t, length: 0.7, peak: 0.2 }); },
            beep: (ctx, out, t) => { [0, 0.25, 0.5].forEach((d) => tone(ctx, out, { type: 'square', from: 1000, start: t + d, length: 0.15, peak: 0.5 })); },
            alarm: (ctx, out, t) => { [0, 0.8].forEach((d) => tone(ctx, out, { type: 'sawtooth', from: 600, to: 1200, start: t + d, length: 0.7, peak: 0.5 })); },
        };

        const settings = () => { try { return { ...DEFAULTS, ...JSON.parse(read(KEY, '{}')) } } catch (e) { return { ...DEFAULTS } } };

        const quietNow = (s) => {
            if (! s.quiet) return false;
            const now = new Date();
            const minutes = now.getHours() * 60 + now.getMinutes();
            const [fh, fm] = s.quietFrom.split(':').map(Number);
            const [th, tm] = s.quietTo.split(':').map(Number);
            const from = fh * 60 + fm, to = th * 60 + tm;
            return from <= to ? minutes >= from && minutes < to : minutes >= from || minutes < to;
        };

        const play = async (sound, volume) => {
            try {
                const ctx = audio();
                if (ctx.state === 'suspended') await ctx.resume().catch(() => {});
                const out = ctx.createGain();
                out.gain.value = Math.max(0, Math.min(1, volume));
                out.connect(ctx.destination);
                (SOUNDS[sound] ?? SOUNDS.chime)(ctx, out, ctx.currentTime + 0.02);
                return ctx.state === 'running';
            } catch (e) {
                return false;
            }
        };

        let last = null;
        let playedAt = 0;

        window.addEventListener('ring-master-counts', ({ detail }) => {
            const count = Number(detail.inbox ?? 0);
            const previous = last;
            last = count;
            const s = settings();

            if (! s.on || count === 0 || quietNow(s)) return;

            const now = Date.now();
            const fresh = previous !== null && count > previous;
            const due = s.mode === 'repeat' && now - playedAt >= s.every * 60_000;

            // One tab rings for all of them.
            if ((fresh || due) && now - Number(read(LAST, 0)) > 4000) {
                write(LAST, String(now));
                playedAt = now;
                play(s.sound, s.volume);
            } else if (previous === null) {
                playedAt = s.mode === 'repeat' ? now - s.every * 60_000 : now;
            }
        });

        window.RingMasterAlarm = { KEY, DEFAULTS, SOUNDS: Object.keys(SOUNDS), settings, save: (s) => write(KEY, JSON.stringify(s)), play };
    })();
</script>
