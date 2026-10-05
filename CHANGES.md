# Changes

## 0.5.0 (2026100700)

Fixes from an adversarial production review.

- **Expected lists are frozen when a class ends**, not a day later, with each user's groups, so
  group views of past classes use the groups at the time. A class reached long after it ended
  while its activity is hidden is not frozen (0.4.0 froze such classes with no students); the
  upgrade repairs those. The first sync after an upgrade freezes at most 2000 classes per run.
- **Not held needs proof**: besides the Zoom plugin's report position, the host must have a later
  session on record. A session without a participant report shows *No participant report* and
  counts for nobody.
- **Activities sharing a Zoom meeting**: each session counts where its schedule matches; a class
  held under another such activity shows *Held in another activity*, not *Not held*.
- **Teacher figures are protected**: while teacher attendance is tracked, setting class windows
  and linking Zoom identities to teachers need `excludetracked` too. Switching tracking off and
  on no longer wipes earlier not-held classes.
- **Restore**: a sync running during a restore no longer deletes the classes being restored;
  restored classes keep their real times; restored classes no longer make every sync revisit
  their activity.
- **Caching**: one cache entry per summary instead of one per change (the default file cache
  never removes expired entries); per-course versions; a sync that changed nothing keeps the
  cache. Viewers who see different identity fields do not share cached summaries.
- **Sync**: recent mod_zoom rows are looked at again (rows committed late), rows changed in place
  are noticed, and the first sync after upgrading is a full pass.
- **Retention**: at least 30 days, judged by class start.
- Download headings are protected from spreadsheet formulas too.

## 0.4.0 (2026100602)

Production readiness, from a full audit.

- **Past classes keep who was expected.** Once a class is over (after the *Not held after*
  delay), the sync freezes its expected students and teachers. Unenrolling, suspending or
  changing a user's role no longer rewrites past attendance and headcounts. The first sync after
  upgrading freezes every past class from the enrolments at that time.
- **Safe downloads.** Text that a spreadsheet would read as a formula (Zoom display names are
  typed by participants) is written as plain text.
- **Excluding a class needs a reason**, shown wherever the class is listed and recorded in the
  logs. While teacher attendance is tracked, excluding takes the new capability
  `local/zoomattendance:excludetracked` (managers by default).
- **Responsible teachers** per activity: limit teacher attendance to the chosen teachers.
- **Data retention**: *Keep classes for (days)*, off by default.
- **Faster hourly sync**: only activities with new Zoom data, schedule or settings changes are
  visited, with a full pass once a day. Identity links are recomputed in the background.
- **Cached reports**, rebuilt as soon as attendance, enrolments, roles, groups or settings change;
  the course report shows 50 students per page.
- **Backup and restore** of settings, responsible teachers and, with user data, past classes,
  their figures, frozen lists and identity links.
- **Health checks** on the system status report: the attendance sync and the Zoom plugin's
  report task.
- **Moodle app**: a *Zoom attendance* tab in courses.
- A class first seen after it ended is never marked *Not held* for teachers.
- The link page only offers users who can be expected, with the identity fields the viewer may
  see. A user's page only opens for users of the course. Numeric settings accept whole numbers
  only. Tables have captions for screen readers.
- Maturity is now beta. CI also runs Moodle 4.1 on PHP 7.4, and Behat features.

## 0.3.7

Wording: "class" everywhere, accurate window, exclusion and empty-list texts.

## 0.3.6

The class headcount counts present and partial together as present overall.

## 0.3.5

Class headcount: out of the expected students, how many were present, partial and absent.

## 0.3.4 and earlier

Teacher attendance ("When joined", progress bars, regular meeting time windows, teacher
visibility rules), student attendance reports, identity links, inferred windows and exclusions.
See docs/ARCHITECTURE.md.
