# Campaigns

A campaign is one change made across many packages of a workspace: "move every package to Pest 5", "rename the Cortex namespace", "adopt standard X". RingMaster works out the order from the packages' Composer dependencies and queues one task per package, each waiting on the tasks of the packages it requires. The change then lands in dependency order, and every task starts from its dependencies' merged work.

## Launching one

On **Campaigns** (`/campaigns`):

1. Give it a title, and say what every package should change, how to tell it is done, and what must not change.
2. Pick the workspace and its packages (all by default; the workspace root itself is never a campaign package).
3. Check **lands in this order**: the packages in waves. A package is in a later wave than every package it requires (`require` or `require-dev`, matched by Composer name among the picked packages). Packages that require each other (Composer allows cycles) go side by side in a last wave, and the page says so.
4. Pick a priority and **Launch campaign**.

Each package gets a task titled `<title> (<package>)`, with the campaign's text and a note saying which campaign it belongs to, to change only that package, which packages land before it, and that packages requiring it follow once its PR is merged, so it should keep what they use working. Its dependencies are ordinary [job graph](tasks.md#the-job-graph) dependencies: the task resumes when every package it requires is done or merged, with a resume note to pull them in.

## Following one

The campaign's page shows a progress bar and every package's task per wave, with its status and its spec's stage, refreshing every few seconds. A campaign is **done** once every task is done. **Cancel campaign** cancels its open tasks; spec folders and PRs are left as they are.

Nothing about a campaign merges anything: each package's PR waits for you like any other, and the next wave starts only after you merge.
