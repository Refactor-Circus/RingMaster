# Decision precedent

Every architectural answer you give in RingMaster is kept as precedent. When a new question reads like one you answered before, RingMaster shows your past answer as a suggestion, and sessions are told about it before they ask. Nothing is ever applied without your click: the decision stays yours, you just stop making the same one twice.

## What is kept

Each answer to a **decision** (not escalations): the question, its options, your answer and note, the project and spec, and when. Changing an answer replaces its precedent.

## How questions are matched

On their words, not their phrasing: both questions are lower-cased, stop words and words under three letters are dropped, plurals and -ing/-ed endings folded, and the overlap of the two word sets is measured (Jaccard similarity, 0 to 1). "Should exports be queued, or sync?" and "Queued or sync exports?" match fully. A precedent counts at `RING_MASTER_PRECEDENT_SIMILARITY` (default 0.5) or more.

The past answer is mapped onto the new question's options: the same text, or the option whose words overlap it most (at least half). When none fits, the past answer is offered as written.

## In the Inbox

An open decision with a precedent shows, above its options:

> You chose **Queued** for "Sync or queued?" in Alpha on Oct 24, 2026 (75% alike) — **Answer the same**

**Answer the same** answers with that option and a note pointing at the precedent (and its note). You can still change the answer until Claude applies it. The [briefing](briefing.md) lists the precedent next to each open question too.

## For sessions

The MCP tool [`precedents`](mcp.md#precedents-read-only) returns past answers for a question. Ringside's skills (spec review, factory, decide, inbox) call it before writing a question to `questions.yml`. With a precedent of 0.5 or more they still ask, but recommend the matching option and start the evidence with "Precedent: you chose … for … in … on …".
