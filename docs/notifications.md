# Notifications

RingMaster tells you when something needs you. Pick channels in `.env`:

```dotenv
RING_MASTER_NOTIFY=desktop,ntfy                               # any of desktop, mail, webhook, ntfy
RING_MASTER_NOTIFY_MAIL=you@example.com                       # mail: through Laravel's mailer (MAIL_* settings)
RING_MASTER_NOTIFY_WEBHOOK=https://hooks.slack.com/services/…  # POSTs {"text": …, "content": …}
RING_MASTER_NOTIFY_NTFY=https://ntfy.sh/ring-master-7f3c9a1e2b   # ntfy: the topic's URL
RING_MASTER_URL=https://ringmaster.test                       # where the links point
```

| Channel | How |
| --- | --- |
| `desktop` | `osascript` on macOS, `notify-send` on Linux, on the machine RingMaster runs on. The text is passed as arguments, never into the script |
| `mail` | one mail per notification to `RING_MASTER_NOTIFY_MAIL`, with an "Open RingMaster" button. Needs a mailer configured |
| `webhook` | a JSON POST with the text in both `text` and `content`, which Slack and Discord accept |
| `ntfy` | a push through [ntfy](https://ntfy.sh) to `RING_MASTER_NOTIFY_NTFY` (the topic's URL; `RING_MASTER_NOTIFY_NTFY_TOKEN` for a protected topic), with up to three [action buttons](phone.md) and the link to open |

A channel that fails is logged and never holds up the scan or the runner. Without channels nothing is sent. **Send a test notification** on the Settings page checks them.

## What sends one

| Notification | When |
| --- | --- |
| N things need you | a scan found new questions or escalations (one notification per scan, however many) |
| A task failed | a task ran out of attempts, or a session released it as failed |
| A task is paused | a session handed a task with a spec back |
| Could not apply your answers | an answers run failed |
| PR checks fail | failing checks reached the Inbox (auto-fix used up) |
| A follow-up job is proposed | a session proposed a job |
| The planner is done | a plan run finished, with how many jobs it suggested |
| A blocked task resumed | every task it waited on finished |
| Runner backing off | a run hit a usage limit |
| Merged branches to clean up / A Dependabot PR needs you | the janitor raised a chore |
| Your briefing | the morning [briefing](briefing.md) is ready |
| Night shift budget spent | tonight's runs reached the night budget |
| The factory analyst is done / A factory improvement is ready to evaluate / … was evaluated | the [factory](factory.md) moved on |

Several carry buttons that act from the notification; see [Phone actions](phone.md).

## Browser notifications

An open RingMaster tab can show notifications too: enable them on the Settings page. They fire for new questions and escalations and for failed tasks. Browsers only ask for permission on https or localhost.
