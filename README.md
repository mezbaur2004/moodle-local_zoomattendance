# Zoom attendance (local_zoomattendance)

A Moodle local plugin that turns the session and participant data the
[Zoom activity plugin (mod_zoom)](https://github.com/ncstate-delta/moodle-mod_zoom)
already stores into per-occurrence attendance (present / partial / absent) with reports.

It adds no Zoom API integration of its own and does not modify mod_zoom. It only reads
mod_zoom's tables and the calendar events mod_zoom writes.

## Requirements

- Moodle 4.1 or later
- mod_zoom v5.5.1 (`2026082400`) or later, with its "Get meeting reports" task running

## Installation

Install into `local/zoomattendance` and complete the upgrade from
*Site administration > Notifications* (or `php admin/cli/upgrade.php`).

## Configuration

*Site administration > Plugins > Local plugins > Zoom attendance*:

| Setting | Default | Meaning |
|---|---|---|
| Track attendance by default | off | If off, teachers turn attendance on per activity |
| Present threshold | 75% | Minimum attendance for present, when joined within the late period |
| Partial threshold | 50% | Minimum attendance for partial; below it is absent |
| Late after | 10 min | Joining later than this after the start makes a participant partial, not present |
| Measure attendance against | Scheduled length | Or the time the meeting actually ran inside the schedule |
| Early / late margin | 30 / 30 min | How far outside a scheduled window a Zoom session may start or end and still match it |
| Session gap for unscheduled meetings | 30 min | Sessions this close together form one occurrence when there is no fixed schedule |

Teachers with *Manage Zoom attendance settings* can override the enable switch, thresholds
and denominator per activity (*Zoom attendance settings* in the activity's navigation).

## How attendance is calculated

For each scheduled occurrence, every participant's join/leave segments are clipped to the
scheduled window and merged as an interval union, so overlapping connections are not
double-counted. Attended time divided by the window length gives the percentage, and the
thresholds give the status. Statuses are only assigned once mod_zoom has reported a
session for the occurrence; until then it shows as *Upcoming* or *No session data*.

Expected participants are users enrolled with an active enrolment covering the occurrence,
who hold `local/zoomattendance:betracked` (students by default) and can access the
activity. Other matched Moodle users and unmatched Zoom participants are listed
separately and do not count in the totals.

The course summary has one column per occurrence with session data, showing that
occurrence's status and percentage, and a *Course overall* column. Course overall is
total attended time over the total time of the occurrences the participant was expected
at, so longer occurrences weigh more. It is a percentage only, with no status. The
per-user page shows the same course overall at the top.

A participant is *partial* when they joined but are not present: they stayed below the
present threshold (for example joined on time and left early) or joined after the late
period.

The hourly task `\local_zoomattendance\task\sync` snapshots occurrences (mod_zoom may
later delete past calendar events) and recomputes only occurrences whose source data
changed. Teachers can also press *Recompute now*.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the full design, including the
verified behaviour of mod_zoom it relies on.

## Capabilities

| Capability | Default roles | Purpose |
|---|---|---|
| `local/zoomattendance:viewreports` | teacher, editing teacher, manager | Activity, course and per-user reports |
| `local/zoomattendance:viewown` | student, teacher, editing teacher | Own attendance |
| `local/zoomattendance:betracked` | student | Being an expected participant |
| `local/zoomattendance:manage` | editing teacher, manager | Per-activity settings, exclude occurrences, recompute |

## Correcting attendance

Teachers with *Manage Zoom attendance settings* at course level can fix the two cases the
automatic matching cannot:

- **Unmatched participants.** In an occurrence's detail, *Link to user* next to an unmatched
  Zoom participant (for example "iPhone" or a personal email) links that Zoom identity to an
  enrolled user. The link applies to every Zoom activity in the course, past and future. The
  participant's time merges with the user's own, and the user is marked *Linked by teacher*.
  *Zoom identity links*, at the bottom of the activity report, lists the course's links and
  can remove them.
- **Inferred windows.** For occurrences inferred from sessions (meetings without a fixed time),
  *Set window* in the occurrence list sets the real class time. Time is clipped to it and
  percentages are measured against it. *Revert* returns to the inferred window. Scheduled
  windows come from the Zoom activity and cannot be edited here.

Both changes recompute attendance immediately. If a sync is running at that moment, they
apply on the next hourly sync.

## Development

PHPUnit tests live in `tests/`. They create mod_zoom data directly, so no Zoom account is
needed. CI runs `moodle-plugin-ci` against Moodle 4.1, 4.5 and 5.0.

## License

GNU GPL v3 or later.
