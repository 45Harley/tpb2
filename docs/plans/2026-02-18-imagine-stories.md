# Imagine Stories — Group Invite Funnel

**Purpose:** 4 story pages linked from invite email. Each is a standalone PHP page (`talk/imagine.php?story=N&token=xxx`). Invite accept token passes through every link.

**Arc:** Pain → Proof → Personal → Possibility

---

## Story 1: "Imagine they raised your taxes and nobody asked you."

Last year, a small town in Connecticut got a 14% property tax increase — approved by nine people in a half-empty room on a Wednesday night.

The other 8,000 residents found out when the bill arrived. Some were angry. Most just shrugged — because what were they supposed to do?

Show up to a meeting they didn't know about? Argue with people who've been doing this for twenty years? Write a letter to someone who won't read it?

That's not democracy. That's a system designed for 1953 still running in 2026. And it's why the same people keep deciding for everyone else.

The People's Branch changes that. Post your idea. Your neighbors vote. The group distills it into something no board can ignore. From your phone, on your time.

**{Facilitator}** invited you because your voice belongs in "{Group Name}." Nine people in a room shouldn't outweigh you.

**Next: [Imagine a neighborhood that fixed it themselves. →](imagine.php?story=2&token=xxx)**

[Yes, I'm In]

No thanks — I'll sit this one out.

---

## Story 2: "Imagine a neighborhood that fixed it themselves."

A parent in Putnam posted one sentence: "The school pickup line is dangerous and nobody's doing anything about it."

Within a week, thirty-one people agreed. Four added better ideas. The group gathered it all into one clear proposal and walked it into the next board meeting.

The board didn't hear one frustrated parent. They heard a community — organized, specific, and impossible to ignore.

No yelling. No petition nobody reads. No Facebook rant that disappears by Thursday. Just a clear idea, backed by real people, delivered in a format that demands a response.

That parent didn't need connections. Didn't need a law degree. Didn't need to run for office. They needed sixty seconds and a place that actually listens.

**{Facilitator}** thinks you're that person. "{Group Name}" is where it starts.

**Next: [Imagine your idea is the one that changes everything. →](imagine.php?story=3&token=xxx)**

[Yes, I'm In]

No thanks — I'll sit this one out.

---

## Story 3: "Imagine your idea is the one that changes everything."

You've said it at the dinner table, in the car, standing in line at the post office — "somebody should really fix that."

But there was nowhere to put it. No suggestion box that actually goes anywhere. No way to know if your neighbor was thinking the exact same thing.

Until now. You type it. Your neighbors vote on it. The group sharpens it. And suddenly it's not just your opinion — it's a mandate.

You don't need to be loud. You don't need to know the right people. You don't need to show up anywhere. You just need one idea and two minutes.

And when thirty of your neighbors agree with you? That's not a complaint anymore. That's a movement — with a paper trail.

**{Facilitator}** is building that room right now. It's called "{Group Name}." There's a seat with your name on it.

**Next: [Imagine every town in America doing this. →](imagine.php?story=4&token=xxx)**

[Yes, I'm In]

No thanks — I'll sit this one out.

---

## Story 4: "Imagine every town in America doing this."

Right now, 19,502 towns in America make decisions the same way they did in 1953 — a handful of people in a room, on a night most people can't make it.

But what if every resident could weigh in — from their phone, on their schedule, in plain language — and the best ideas rose to the top automatically?

That's not a fantasy. It's already happening. Town by town, group by group, one idea at a time.

Schools, roads, budgets, parks, public safety, zoning, housing — every civic decision that shapes your daily life, made better because the people who live it finally have a seat at the table.

Not someday. Not when the right politician shows up. Now. Because the tool exists, the door is open, and your town is waiting for your voice.

**{Facilitator}** already started. "{Group Name}" is live. The only thing missing is you.

**[How it works →](help.php)**

[Yes, I'm In]

No thanks — I'll sit this one out.

---

## Email Template (updated)

**Subject:** {Facilitator} invited you to "{Group Name}" — your neighbors are talking

**Body:**

> **{Facilitator}** invited you to **"{Group Name}"** on The People's Branch.
>
> {Group description}
>
> **[Imagine they raised your taxes and nobody asked you. →](imagine.php?story=1&token=xxx)**
>
> **[Imagine a neighborhood that fixed it themselves. →](imagine.php?story=2&token=xxx)**
>
> **[Imagine your idea is the one that changes everything. →](imagine.php?story=3&token=xxx)**
>
> **[Imagine every town in America doing this. →](imagine.php?story=4&token=xxx)**
>
> [Yes, I'm In]
>
> *This invitation expires in 7 days.*

---

## Implementation Notes

- Single file: `talk/imagine.php?story=N&token=xxx`
- Token passthrough: accept token in every Yes link
- "No thanks" links to decline URL (quiet, small text)
- Story 4 ends with link to `help.php` instead of next story
- Static content — same stories for all groups
- Dynamic: {Facilitator} name and {Group Name} pulled from token lookup
- Page style: dark theme matching Talk, minimal chrome, full-width text
