# Changes

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
