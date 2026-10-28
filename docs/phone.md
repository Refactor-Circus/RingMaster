# Phone actions

Notifications can carry buttons, so you can deal with RingMaster from your phone: accept a proposed job, approve a chore, answer a question, review the low-risk PRs from the briefing.

## Set it up

1. Make RingMaster reachable from your phone **privately**: [Tailscale](https://tailscale.com) on both (recommended), or your home network. RingMaster has no login; never expose it to the internet.
2. Point the links there:

   ```dotenv
   RING_MASTER_URL=http://your-mac.your-tailnet.ts.net
   ```

3. Add the ntfy channel and subscribe to the topic in the ntfy app. Pick a long, unguessable topic name, or use your own ntfy server with an access token:

   ```dotenv
   RING_MASTER_NOTIFY=desktop,ntfy
   RING_MASTER_NOTIFY_NTFY=https://ntfy.sh/ring-master-7f3c9a1e2b
   RING_MASTER_NOTIFY_NTFY_TOKEN=          # optional: an ntfy access token
   ```

Mail notifications list the same buttons as links.

## Which notifications have buttons

| Notification | Buttons |
| --- | --- |
| A follow-up job is proposed | Accept and queue · Decline |
| Merged branches to clean up | Delete them on GitHub · Leave them |
| A Dependabot PR needs you | Merge it / Queue a fix · Leave it |
| 1 thing needs you (one new question) | each option, when a decision has three or fewer; **Approve as is** for an escalation (sending back needs a note, so do that in the app) |
| Your briefing | Review N low-risk PRs |

ntfy shows up to three buttons.

## How the links are protected

- Each button is a **single-use** link with a 48-character random token, **signed** with the app key (Laravel signed URLs, signed on the path so any host you reach it by works) and **expiring** after `RING_MASTER_PHONE_TTL_HOURS` (default 24).
- Opening a link (GET) only shows what it does, with a button to confirm. Only a POST acts: the confirm button, or ntfy's button, which POSTs directly. Link previews and prefetching cannot trigger anything.
- The token is claimed before the action runs, so two taps cannot both act. A used, expired or tampered link is refused (410 or 403).
- The `/act/*` routes skip CSRF checks, since ntfy posts without a session; the signed single-use token is the check.

Set `RING_MASTER_PHONE_ACTIONS=false` to send notifications without buttons.
