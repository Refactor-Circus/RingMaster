<div wire:poll.15s="check" class="hidden"></div>

@script
<script>
    $wire.on('ring-master-notify', ({ title, body, url }) => {
        let enabled = false;
        try { enabled = localStorage.getItem('ring-master.notifications') === 'on'; } catch (e) {}

        if (! enabled || ! ('Notification' in window) || Notification.permission !== 'granted') {
            return;
        }

        const notification = new Notification(title, { body, tag: 'ring-master' });
        notification.onclick = () => { window.focus(); window.location.href = url; };
    });
</script>
@endscript
