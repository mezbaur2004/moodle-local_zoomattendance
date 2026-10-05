# Zoom attendance (local_zoomattendance)

A Moodle local plugin that turns the session and participant data the
[Zoom activity plugin (mod_zoom)](https://github.com/ncstate-delta/moodle-mod_zoom)
already stores into per-class attendance (present / partial / absent) with reports.

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
| Session gap for unscheduled meetings | 30 min | Sessions this close together form one class when there is no fixed schedule |

Teachers with *Manage Zoom attendance settings* can override the enable switch, thresholds
and denominator per activity (*Zoom attendance settings* in the activity's navigation).

## How attendance is calculated

For each scheduled class, every participant's join/leave segments are clipped to the
scheduled window, so time in the room before the start or after the end never counts. Zoom
lists only upcoming classes of a recurring meeting, so classes held before the plugin was
installed have no calendar event. For a recurring meeting with a fixed time, those classes use
the meeting's regular time and length on that day. Only meetings without a fixed time, or
sessions at another time of day, fall back to the span the meeting actually ran. Segments are
merged as an interval union, so overlapping connections are not
double-counted. Attended time divided by the window length gives the percentage, and the
thresholds give the status. Statuses are only assigned once mod_zoom has reported a
session for the class; until then it shows as *Upcoming* or *No session data*.

Expected participants are users enrolled with an active enrolment covering the class,
who hold `local/zoomattendance:betracked` (students by default) and can access the
activity. Other matched Moodle users and unmatched Zoom participants are listed
separately and do not count in the totals.

The course summary is linked from the course navigation as *Zoom attendance* (students see
*My Zoom attendance*), placed right after *Grades*. Moodle shows at most five course
navigation items, so the item that was fifth moves under *More*. The placement uses a hook
that exists from Moodle 4.4; on older versions the link stays under *More*.

The course summary has one column per class with session data, showing that
class's status and percentage, and a *Course overall* column. Course overall is
total attended time over the total time of the classes the participant was expected
at, so longer classes weigh more. It is a percentage only, with no status. The
per-user page shows the same course overall at the top.

Each class cell has a slim bar in its status colour. Course overall has a bar with a line at
the Present threshold of the site defaults, green from Present, orange from Partial and red below;
the colours are a visual cue, not a status.

Under each class, a *Students* row shows how many of the expected students were present
overall, counting present and partial together, for example "17 of 18 present", with a bar split
green, orange and red and the breakdown "15 present + 2 partial · 1 absent". The same headcount is on each class's own page, in a *Students* column on the teacher
attendance page (for users who see the student reports), in both downloads and in the dashboard
block. In separate groups, a teacher without access to all groups counts their own groups only.

A participant is *partial* when they joined but are not present: they stayed below the
present threshold (for example joined on time and left early) or joined after the late
period.

The hourly task `\local_zoomattendance\task\sync` snapshots classes (mod_zoom may
later delete past calendar events) and recomputes only classes whose source data
changed. Teachers can also press *Recompute now*.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the full design, including the
verified behaviour of mod_zoom it relies on.

## Capabilities

| Capability | Default roles | Purpose |
|---|---|---|
| `local/zoomattendance:viewreports` | teacher, editing teacher, manager | Activity, course and per-user reports |
| `local/zoomattendance:viewown` | student, teacher, editing teacher | Own attendance |
| `local/zoomattendance:betracked` | student | Being an expected participant |
| `local/zoomattendance:manage` | editing teacher, manager | Per-activity settings, exclude classes, recompute |
| `local/zoomattendance:betrackedteacher` | teacher, editing teacher | Being an expected teacher (teacher attendance) |
| `local/zoomattendance:viewteacherreports` | manager | Every teacher's attendance |
| `local/zoomattendance:viewownteacher` | teacher, editing teacher | Own teacher attendance |
| `local/zoomattendance:viewnoneditingteachers` | editing teacher | Non-editing teachers' attendance |

## Correcting attendance

Teachers with *Manage Zoom attendance settings* at course level can fix the two cases the
automatic matching cannot:

- **Unmatched participants.** In a class's detail, *Link to user* next to an unmatched
  Zoom participant (for example "iPhone" or a personal email) links that Zoom identity to an
  enrolled user. The link applies to every Zoom activity in the course, past and future. The
  participant's time merges with the user's own, and the user is marked *Linked manually*.
  *Zoom identity links*, at the bottom of the activity report, lists the course's links and
  can remove them.
- **Inferred windows.** For classes inferred from sessions (meetings without a fixed time)
  or using a recurring meeting's regular time, *Set window* in the class list sets the
  real class time. Time is clipped to it and
  percentages are measured against it. *Revert* returns to the automatic window. Scheduled
  windows come from the Zoom activity and cannot be edited here.

Both changes recompute attendance immediately. If a sync is running at that moment, they
apply on the next hourly sync.

Each change, and excluding a class, records who made it and is written to the Moodle
logs, because it can also change teacher attendance.

## Teacher attendance

Off by default. Switch on *Track teacher attendance* in the plugin settings, under
*Teacher attendance*, to evaluate teachers on how accurately they attend their own scheduled
Zoom classes.

- **Who:** users enrolled in the course with *Be expected as a teacher* (editing and
  non-editing teachers by default). Every teacher is expected at every class of every Zoom
  activity in their course.
- **Thresholds:** site-level only, so teachers cannot change their own bar. Present from 90 %
  of the scheduled time when joined within 5 minutes, partial from 10 %, otherwise absent (did not join, or under 10 %). All
  three are configurable. Teachers are always measured against the scheduled time.
- **Late starts and early leaves:** each cell shows how many minutes late the teacher joined
  and how many minutes early they left.
- **Classes that were not held** count as absent for their teachers once the Zoom plugin has
  fetched meeting reports at least 24 hours (configurable) past the class. If the Zoom
  plugin's report task is failing, nothing is marked not held. Only classes with a fixed
  schedule can be detected, and never classes from before teacher tracking was switched on.
  Students are not affected.
- **Integrity:** while teacher tracking is on, every Zoom activity is synced, even where
  attendance tracking is turned off for it. Managers see who excluded a class, and
  classes where a teacher's time includes a Zoom participant they linked to themself are
  flagged.

**Pages:**
- *Teacher attendance*, linked from the course's Zoom attendance page: one row per class, in
  date order, and one column per teacher, with each teacher's attendance and counts at the
  bottom. A date filter defaults to the course's first class. The download has one row per
  class and teacher.
- *Teacher attendance: all courses*, under *Site administration > Reports* and in the category
  menu: one row per teacher and course with their role, classes, present, partial, absent,
  attendance and notes, a date range (default the last 30 days) and a category filter. The
  download adds late starts, early leaves and the other detail counts. Filtered pages can be
  bookmarked.
- *My teaching attendance*, on a teacher's own profile.

Beside each teacher's Attendance, *When joined* counts only the classes the teacher joined:
classes they missed or that were not held are left out. It shows how fully a teacher attends the
classes they do take, which matters where several teachers share a course's classes.

Each class shows a slim bar in its status colour, and each overall figure a bar with a line at
the Present threshold: green from Present, orange from Partial, red below. Each page has a
*What the statuses mean* legend. Besides Present, Partial and Absent, a class
can show *Not held* (counts as absent), *Excluded*, *Awaiting Zoom report* or *Zoom data reset*
(none of these three counts).

Managers see every teacher. Editing teachers see their own figures and the non-editing
teachers' in their courses, but not other editing teachers. Non-editing teachers see only their
own figures. Teachers they may not see are hidden from them in the existing reports too.

This is staff monitoring: inform teachers, and check local employment and data-protection
rules, before using the figures for evaluation.

## Development

PHPUnit tests live in `tests/`. They create mod_zoom data directly, so no Zoom account is
needed. CI runs `moodle-plugin-ci` against Moodle 4.1, 4.5 and 5.0.

## License

GNU GPL v3 or later.
