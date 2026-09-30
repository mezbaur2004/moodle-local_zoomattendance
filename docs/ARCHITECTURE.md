# local_zoomattendance — Architecture (design only)

Status: **phases 1 and 2 implemented.** Decisions D1–D16 record the adopted choices; sections B13
and B14 list where the code differs from or refines this design.
Scope: a Moodle local plugin that turns data `mod_zoom` has already stored
about sessions and participants into per-occurrence attendance (present / late / absent)
with reports.

```
mod_zoom tables (zoom, zoom_meeting_details, zoom_meeting_participants, event)
        │  read-only SQL
        ▼
local_zoomattendance  ──►  occurrence snapshot + per-user attended seconds (cache)
        │
        ▼
status (present/late/absent) computed at read time from thresholds ──► reports
```

The document has two parts, kept separate on purpose:

* **Part A — Verified facts about `mod_zoom`**: every claim has a `file:line` citation.
  Anything I could not verify is marked **UNVERIFIED**.
* **Part B — Proposed design**: my decisions and recommendations. Nothing in Part B
  is a claim about how `mod_zoom` behaves.

---

## 0. Environment as inspected

| Item | Value found |
|---|---|
| Repository path | `/home/user/moodle-local_zoomattendance` (remote `github.com/mezbaur2004/moodle-local_zoomattendance`) |
| Branch | `claude/happy-euler-k6lhmh`, empty repo with no commits before this document |
| Moodle core in environment | **None.** There is no Moodle checkout or `config.php` in the container, so the target Moodle version could not be inspected; it was decided as Moodle 4.1 LTS+ (D1). |
| `mod_zoom` in environment | **None installed.** For this analysis I cloned upstream `https://github.com/ncstate-delta/moodle-mod_zoom`, branch `main`, commit `b0186fca944112ea140b8eeb963d2f4348ade24a` (2026-09-22), into a scratch directory outside the repo. |
| `mod_zoom` version | `$plugin->version = 2026082400`, `$plugin->release = 'v5.5.1'`, `$plugin->requires = 2019052000` (Moodle 3.7) — `version.php` |
| PHP (CLI) | 8.4.19 |

All `file:line` references below are relative to the `mod_zoom` root (`mod/zoom/`) at
that commit. If production runs a different `mod_zoom` release, re-check the citations
(D2).

---

# Part A — Verified `mod_zoom` findings

## A1. Tables and columns relevant to attendance

Source: `db/install.xml`.

### `zoom` (one row per activity instance) — `db/install.xml:3-57`
| Column | Meaning | Ref |
|---|---|---|
| `id` | instance id (= `course_modules.instance`) | :5 |
| `course` | course id | :6 |
| `meeting_id` | Zoom meeting/webinar id (int 15). **Indexed but not unique.** | :11, :55 |
| `host_id` | Zoom host user id | :14 |
| `start_time` | scheduled start (unix s), "for scheduled meeting only" | :16 |
| `duration` | scheduled duration, **in seconds** (the API's minutes × 60, see `lib.php:291-293`) | :30 |
| `recurring` | 0/1 | :18 |
| `recurrence_type` | 0 = no fixed time, 1 = daily, 2 = weekly, 3 = monthly (`locallib.php:94-97`) | :19 |
| `repeat_interval`, `weekly_days`, `monthly_*`, `end_times`, `end_date_time` | recurrence rule fields | :20-28 |
| `webinar` | 0/1 | :29 |
| `timezone` | meeting timezone | :31 |
| `exists_on_zoom` | 1 = exists, 0 = expired/not found (`locallib.php:53-54`) | :42 |
| `grading_method` | `entry` / `period` (mod_zoom's own grading) | :10 |

### `zoom_meeting_details` (one row per *actual* Zoom session UUID) — `db/install.xml:58-76`
| Column | Meaning | Ref |
|---|---|---|
| `id` | PK | :63 |
| `uuid` | Zoom meeting-instance UUID, **unique key** | :60, :73 |
| `meeting_id` | Zoom meeting id | :61 |
| `start_time`, `end_time` | **actual** session start/end (unix s) | :64, :62 |
| `duration` | since upgrade `2024070300` recomputed as `end_time - start_time` seconds (`db/upgrade.php:980-987`) | :65 |
| `topic`, `total_minutes`, `participants_count` | informational | :66-68 |
| `zoomid` | FK → `zoom.id` | :69, :74 |

There is **no occurrence-id column**: nothing links a session UUID to a scheduled
occurrence of a recurring meeting.

### `zoom_meeting_participants` (one row per join→leave segment) — `db/install.xml:77-98`
| Column | Meaning | Ref |
|---|---|---|
| `id` | PK | :79 |
| `userid` | matched Moodle user id, **nullable** (NULL = unmatched) | :80, index :95 |
| `zoomuserid` | Zoom's per-meeting `user_id` | :81 |
| `uuid` | participant UUID (nullable, "sometimes blank from Zoom" — `classes/task/get_meeting_reports.php:246`) | :82, index :96 |
| `user_email` | participant email (nullable) | :83 |
| `join_time`, `leave_time` | unix s | :84-85 |
| `duration` | seconds, as reported by Zoom | :86 |
| `name` | display name, or the matched Moodle name | :87 |
| `detailsid` | FK → `zoom_meeting_details.id` | :88, :92 |

A participant who drops and rejoins gets **several rows** for the same session. Rows can
overlap, for example when the same person joins from two devices.

### Moodle core `event` table (as used by mod_zoom)
mod_zoom writes one calendar event per scheduled occurrence:
`modulename='zoom'`, `instance=zoom.id`, `timestart`, `timeduration` (seconds), and for
recurring meetings `uuid = occurrence_id` (`lib.php:601-633`, especially :604, :607, :615-624).
For recurring meetings with no fixed time, the events are created with `visible = false`
(`lib.php:626-630`).

## A2. How the Sessions Report gets its data

1. **Entry point**: `report.php`. Access check: `require_login()` plus
   `zoom_get_instance_setup()`, which does `require_login($course, true, $cm)` and
   `require_capability('mod/zoom:view')` (`locallib.php:172-196`), then
   `require_capability('mod/zoom:addinstance', $context)` (`report.php:37`).
   The link on `view.php` only appears for users with `mod/zoom:addinstance`
   (`view.php:40`, `view.php:422-425`).
2. **Data**: `zoom_get_sessions_for_display($zoom->id)` (`report.php:52`, defined at
   `locallib.php:204-260`):
   * reads `zoom_meeting_details` with `zoomid = ?`, ordered by `start_time` (`locallib.php:213`);
   * for each row, calls `zoom_get_participants_report($detailsid)` (`locallib.php:218`), which
     selects `id, name, userid, user_email, join_time, leave_time, duration, uuid` from
     `zoom_meeting_participants` with `detailsid = ?` (`locallib.php:462-480`);
   * computes a *unique participant count* by de-duplicating on uuid → userid → email
     (`locallib.php:221-252`);
   * the displayed end time is `start_time + duration`, not `end_time` (`locallib.php:256`).
3. **Participants page**: `participants.php` needs `mod/zoom:addinstance`
   (`participants.php:39`) and is disabled when the site setting `zoom/maskparticipantdata`
   is on (`participants.php:53-61`, `report.php:54`, `report.php:74-79`). It uses the same
   `zoom_get_sessions_for_display()` (`participants.php:63`) and shows `idnumber` from
   `get_enrolled_users()` (`participants.php:82`, `participants.php:114-122`).

The report computes nothing itself: no attendance, no interval merging, no schedule
clipping.

## A3. Scheduled tasks (`db/tasks.php`)

| Task | Schedule | Relevance | Ref |
|---|---|---|---|
| `mod_zoom\task\get_meeting_reports` | minute 0, every 6 h | **The only writer of sessions and participants.** | `db/tasks.php:37-44` |
| `mod_zoom\task\update_meetings` | 04:30 daily | Refreshes `zoom` rows and **rewrites calendar events/occurrences** | `db/tasks.php:28-35` |
| others (tracking fields, recordings, ical) | — | not relevant | `db/tasks.php:46-81` |

### `get_meeting_reports` in detail (`classes/task/get_meeting_reports.php`)
* **Watermark**: starts from config `zoom/last_call_made_at`, or 30 days back when unset
  (:119-125). The Zoom API is queried with **day** granularity (`gmdate('Y-m-d')`, :139-140).
  The watermark advances on success (:207-210) or rewinds to `meetingtime - 1` on
  failure (:186-203).
* **Source API**: the Dashboard API if the OAuth scopes allow it, otherwise the Report API
  (:146-166). Meetings are normalised (:975-1012) and sorted by `end_time` (:170-171).
* **Instance lookup**: `get_record('zoom', ['meeting_id' => ...], '*', IGNORE_MULTIPLE)`
  (:532). If several activities share a `meeting_id`, **only one of them** (whichever the
  DB returns) receives the sessions. Meetings with no local `zoom` row are skipped (:532-535).
* **Details upsert** keyed on `uuid` (:540-549).
* **Participants**: fetched through `webservice::get_meeting_participants($uuid, $webinar)`
  (:552; `classes/webservice.php:973-1010`, which uses `report/.../participants` or
  `metrics/.../participants?type=past`).
* **Idempotency**: in one delegated transaction (:569-626), each formatted participant is
  checked for an *exact* existing row on
  `(name, userid, detailsid, zoomuserid, join_time, leave_time)`. The row is inserted only
  if none exists (:589-608). **Rows are insert-only. The task never updates or deletes a
  participant row.** A re-run that matches a user differently (e.g. the enrolment changed
  in between) inserts a **second row for the same segment** with a different
  `userid`/`name`. Consumers must de-duplicate. (Inferred from the condition list at
  :589-596; no test confirms it — treat the duplicate scenario as a risk, not a certainty.)
* The CLI (`cli/get_meeting_report.php`) and the web console (`console/get_meeting_report.php:38`,
  capability `mod/zoom:refreshsessions`) can run the same task for a date range or host,
  which re-enters the same insert-if-absent path.

## A4. Participant → Moodle user matching (`format_participant`, `get_meeting_reports.php:222-318`)

Order of attempts:

1. **Name prefix `(id)Name`**: if the Zoom display name matches `/^\((\d+)\)(.+)$/`, the
   digits are taken as the Moodle user id (:236-242). This goes with the
   `zoom/unamedisplay` setting, which can prefill `(<userid>) Full Name` or `(<userid>)`
   when a user joins from Moodle (`locallib.php:973-991`). Notes:
   * The id is **not validated** against `user`/enrolment at this point. Any participant can
     rename themselves `(42) X` in Zoom and be attributed to user 42.
   * The id-only form `(42)` does **not** match the regex (`.+` needs at least one character after `)`).
2. **Previous match by participant uuid**: if an earlier row with the same `uuid` has a
   `userid`, that userid (and name) is reused (:244-264). This **overrides** step 1.
3. **Email against enrolled users**: `strtoupper(user_email)` is compared with
   `strtoupper(zoom_get_api_identifier($user))` for every user returned by
   `get_enrolled_users(course context)` (:268-270, :326-339).
   `zoom_get_api_identifier` returns the field set in `zoom/apiidentifier` (email, username,
   idnumber or a custom profile field) and falls back to email (`locallib.php:835-854`).
4. **Exact upper-cased full name** against enrolled users (:271-273).
5. **Email against all active, non-suspended users** in the site (`IGNORE_MULTIPLE`) (:274-284).
   So non-enrolled Moodle users can be matched.
6. **Fuzzy name match** (`similar_text` > 60 % **and** best Levenshtein agree, roster ≥ 3)
   (:285-287, :463-501).
7. Otherwise `userid = NULL` and the Zoom name is kept (:288-292).

`get_enrolled_users()` is called without `onlyactive`, so suspended enrolments are part of
the roster used for matching (:329). The **matching method is not stored**, so a consumer
cannot tell a strong (email) match from a weak (name/fuzzy/prefix) one.

## A5. Scheduled window: where start/end come from

* **Non-recurring**: `zoom.start_time` and `zoom.duration` (seconds) (`db/install.xml:16,30`;
  `lib.php:291-300`). A matching calendar event with `timestart`/`timeduration` exists
  (`lib.php:513-514`, `lib.php:615-618`).
* **Recurring with fixed time**: the Zoom API returns `occurrences[]`. mod_zoom
  normalises `start_time` to unix time and `duration` to seconds (`lib.php:309-317`), but
  **does not store occurrences in a mod_zoom table**. They exist only as Moodle calendar
  events (`event.timestart`, `event.timeduration`, `event.uuid = occurrence_id`),
  written by `zoom_calendar_item_update()` (`lib.php:507-560`). mod_zoom reads them back
  itself in `zoom_get_next_occurrence()` (`locallib.php:295-309`).
* **Recurring, no fixed time** (`recurrence_type = 0`): no usable schedule.
  `zoom_get_next_occurrence()` returns 0 (`locallib.php:290-292`).

**Fragility (verified in code).** `update_meetings` calls `zoom_calendar_item_update()`
for every recurring meeting, every day (`classes/task/update_meetings.php:195-199`). That
function **deletes every existing event whose `uuid` is not in the latest API response**
(`lib.php:531-553`). If the response has no `occurrences` at all, it deletes **all** of
the instance's events (`lib.php:515` is skipped, then :550-553 run for every event).
Occurrences with `status = 'deleted'` are dropped (`lib.php:518`).

**UNVERIFIED (Zoom API behaviour, not in mod_zoom code):** whether `GET /meetings/{id}`
without `show_previous_occurrences=true` leaves out past occurrences. mod_zoom does not pass
that parameter (`classes/webservice.php:897-905`). If Zoom does leave them out, **past
occurrence events are deleted from `event` within about a day**. Either way, a consumer
cannot rely on `event` as a permanent record of past occurrences. It must snapshot them
while they exist (see B3).

## A6. Events / hooks

* mod_zoom fires only `course_module_viewed`, `course_module_instance_list_viewed` and
  `join_meeting_button_clicked` (`view.php:42-48`, `index.php:45`, `locallib.php:1003-1011`).
  `join_meeting_button_clicked` carries `other.cmid`, `other.meetingid`, `other.userishost`
  (`classes/event/join_meeting_button_clicked.php:46-55`).
* mod_zoom has **no `db/events.php`** (no observers) and **fires no event when session or
  participant data is synced**. `get_meeting_reports` inserts rows without triggering
  anything (`classes/task/get_meeting_reports.php:540-608`). There is no hook or callback for
  "report data arrived".
* mod_zoom itself reads the `join_meeting_button_clicked` log to find users who clicked Join
  but were not matched (`get_meeting_reports.php:1019-1052`).

## A7. Callable APIs and their stability

| API | Location | Classification |
|---|---|---|
| DB tables `zoom`, `zoom_meeting_details`, `zoom_meeting_participants` | `db/install.xml` | **De-facto contract.** Schema changes are listed in `upgrade.txt` (e.g. `upgrade.txt` v5.2.0/v5.4.0 entries). Not a formal API, but the most stable integration point. |
| `zoom_get_participants_report($detailsid)` | `locallib.php:462-480` | Global function in `locallib.php`. Not formally public, but a plain SELECT. **Usable, but equivalent SQL is safer.** |
| `zoom_get_sessions_for_display($zoomid)` | `locallib.php:204-260` | Presentation helper (formats dates with `userdate`). **Internal; do not depend on it.** |
| `zoom_get_next_occurrence($zoom)` | `locallib.php:270-318` | Internal helper; only the next occurrence. |
| `zoom_get_api_identifier($user)` | `locallib.php:835-854` | Internal helper, useful to reproduce the "strong match" check. |
| `zoom_get_eligible_meeting_participants(context)` | `locallib.php:753-765` | Count only; shows mod_zoom's own "eligible = enrolled + `mod/zoom:view`" rule. |
| `get_meeting_reports::format_participant`, `get_participant_overlap_time`, `grading_participant_upon_duration` | `classes/task/get_meeting_reports.php:222`, `:816`, `:643` | Public methods on a **task class**. Internal implementation; not an API. |
| `mod_zoom\webservice` | `classes/webservice.php` | Public class and methods; one method is documented as used by an external plugin (`classes/webservice.php:396`). Calling it would mean **new Zoom API traffic**, which the constraints forbid while mod_zoom already has the data. |
| `mod_zoom\external` (web services) | `classes/external.php:45` | Not investigated for attendance data. **UNVERIFIED** whether it exposes sessions or participants (it does not appear to be used by the report). |

### Existing attendance-like logic in mod_zoom (for comparison, not reuse)
When `grading_method = 'period'`, mod_zoom grades by duration
(`get_meeting_reports.php:611-624`, `:643-807`). Observations:
* It works **per session UUID** (`detailsid`), not per scheduled occurrence (:675).
* The overlap logic keeps **one aggregate [min join, max leave] span per user** and adds
  durations minus a pairwise overlap (:693-709, :816-850). This is **not a true interval
  union**: after two disjoint segments the stored span covers the gap, so a third segment
  inside the gap is wrongly treated as overlap.
* Clipping to the schedule happens only in the denominator, and only for non-recurring
  meetings (:666-672). Participant intervals are not clipped.

That is why this plugin does its own calculation (B4).

## A8. Lifecycle handled by mod_zoom (relevant to our cleanup)

* **Delete instance**: removes the instance's participants and details (`lib.php:386-392`)
  and its calendar events (`lib.php:398`).
* **Course reset** (`reset_zoom_all`, default on — `lib.php:905-907`): deletes the course's
  `zoom_meeting_participants` but **keeps `zoom_meeting_details`** (`lib.php:846-860`).
* **Backup/restore**: backs up only `zoom` and tracking fields, **not details or participants**
  (`backup/moodle2/backup_zoom_stepslib.php:42-63`). Restore **creates a new Zoom meeting**
  (new `meeting_id`), or sets `meeting_id = 0` / expired on failure
  (`backup/moodle2/restore_zoom_stepslib.php:63-77`).
* **Privacy**: mod_zoom's provider covers `zoom_meeting_participants` by `userid`
  (`classes/privacy/provider.php:55-61`, `:98-125`, `:321-352`, `:359-380`). A privacy delete
  in mod_zoom removes the source rows, but mod_zoom has no way to notify us.
* **Uninstall**: `xmldb_zoom_uninstall()` is a no-op (`db/uninstall.php`). If mod_zoom is
  uninstalled, Moodle drops its tables. Our plugin declares a dependency, so Moodle's plugin
  manager requires removing `local_zoomattendance` first (standard Moodle behaviour,
  **UNVERIFIED** here without core).

## A9. Summary of what mod_zoom does and does not provide

| Need | Provided? |
|---|---|
| Actual sessions per activity | ✅ `zoom_meeting_details` |
| Join/leave segments per participant | ✅ `zoom_meeting_participants` |
| Moodle user match | ✅ `zoom_meeting_participants.userid` (nullable; method unknown; spoofable via name prefix) |
| Scheduled window, non-recurring | ✅ `zoom.start_time` + `zoom.duration` |
| Scheduled window per occurrence (recurring) | ⚠️ only in `event`, and it may be deleted after the fact |
| Session → occurrence link | ❌ must be inferred from time overlap |
| "Data synced" notification | ❌ must poll |
| Interval-union attendance, thresholds | ❌ |

---

# Part B — Proposed design

> Everything below is design, not a claim about `mod_zoom`. Tags **(D#)** point to the decision that fixed a choice.

## B1. Principles

1. **Read-only consumer of mod_zoom.** No patches, no Zoom API calls, no `mod_attendance`,
   and no dependency on PR #730 or its tables. `version.php`:
   `$plugin->dependencies = ['mod_zoom' => 2026082400]` (D2).
2. **Read through our own SQL**, not mod_zoom's helpers. The tables are the most stable
   surface (A7). All mod_zoom table access goes in one class
   (`local_zoomattendance\local\source\zoom_source`), so a schema change breaks one file.
3. **Cache what is expensive or perishable, compute what is cheap.** Occurrence
   schedules are perishable (A5), so we snapshot them. Per-user attended seconds are
   expensive, so we cache them. Status depends on thresholds and is cheap, so we compute it
   at read time. The expected-user list depends on enrolment, which changes, so we also
   compute it at read time.

## B2. Data-flow options

| Option | How | Pros | Cons |
|---|---|---|---|
| **1. Live computation** | Every report view runs the SQL, clips, unions and applies thresholds. | No schema. Always consistent with mod_zoom. Privacy and reset are trivial. | Cost O(sessions × segments) per page load, heavy for course-wide or profile pages across many activities. **Cannot keep past occurrence schedules** once `event` rows are deleted (A5), so recurring attendance breaks. |
| **2. Cached data (lazy)** | Compute on first view and store; invalidate when a fingerprint changes. | Cheap repeat views. No cron dependency. | The first view is slow. Still can't recover occurrences deleted before the first view. Invalidation runs inside web requests. |
| **3. Event/scheduled-task driven** | Observers where events exist, plus our own scheduled task polling mod_zoom tables. | Precomputed and fast reports. The task can **snapshot occurrences daily before mod_zoom deletes them**. | mod_zoom fires no sync event (A6), so this is pure polling in practice. Adds latency after mod_zoom's 6-hourly task. |

**Recommendation: hybrid of 3 + 1**
* A scheduled task `local_zoomattendance\task\sync` (hourly, e.g. minute 20) that:
  1. **snapshots occurrences** from `zoom` + `event` into our table (never deleting past ones);
  2. finds `zoom_meeting_details` rows whose **fingerprint** changed (B5) and recomputes
     attended seconds for the occurrences they touch.
* Core event observers only for **cleanup**: `\core\event\course_module_deleted`,
  `\core\event\course_deleted`, `\core\event\user_deleted`, `\core\event\course_reset_ended`.
* **Live** at read time: expected users, status thresholds, and a "recompute now" button for
  managers (runs the same calculator for one activity, synchronously).
* Stored results are derived data. The whole cache can be dropped and rebuilt from mod_zoom
  **except** occurrence snapshots, which may be the only surviving copy.

## B3. Minimal database schema

Table names start with the component name, as Moodle requires, and stay within Moodle's
28-character limit (without the site prefix).

### `local_zoomattendance_occ` — one row per scheduled (or inferred) occurrence
| Field | Type | Notes |
|---|---|---|
| id | int10 PK | |
| zoomid | int10 | → `zoom.id`, indexed |
| occurrencekey | char(64) | `event.uuid` for recurring, `'single'` for non-recurring, `'s:'.<details uuid hash>` for inferred |
| source | char(10) | `schedule` \| `inferred` \| `manual` |
| timestart | int10 | scheduled/inferred window start |
| timeend | int10 | window end |
| status | int2 | 0 active, 1 cancelled upstream (future occurrence vanished), 2 excluded by teacher |
| timecreated, timemodified | int10 | |
| **unique** | (zoomid, occurrencekey) | makes snapshots idempotent |

### `local_zoomattendance_session` — sync bookkeeping per mod_zoom session
| Field | Type | Notes |
|---|---|---|
| id | int10 PK | |
| detailsid | int10 | → `zoom_meeting_details.id`, **unique** |
| zoomid | int10 | indexed |
| occurrenceid | int10 | → `local_zoomattendance_occ.id`, nullable (NULL = not mapped) |
| fingerprint | char(40) | sha1 of (start_time, end_time, COUNT, MAX(id), SUM(COALESCE(userid,0))) of participants |
| timesynced | int10 | |

### `local_zoomattendance_result` — per occurrence, per identity
| Field | Type | Notes |
|---|---|---|
| id | int10 PK | |
| occurrenceid | int10 | FK, indexed |
| userid | int10 | nullable; set when matched |
| identitykey | char(64) | `u:<userid>` for matched users. For unmatched: `z:`+sha1 of normalised email, else participant uuid, else zoomuserid+name |
| displayname | char(255) | Zoom name for unmatched rows (personal data, see B7) |
| attendedsecs | int10 | length of the union after clipping |
| firstjoin | int10 | first clipped join (nullable) |
| lastleave | int10 | last clipped leave (nullable) |
| segments | int4 | number of merged intervals (diagnostics) |
| matchstrength | int1 | 2 = email/api-identifier equals the user's; 1 = other (name, prefix, fuzzy); 0 = unmatched |
| timemodified | int10 | |
| **unique** | (occurrenceid, identitykey) | idempotent upsert |
| index | (userid) | profile page, privacy |

Absent users get **no row**. Absence is "expected, but no result row".

### `local_zoomattendance_setting` — per-activity overrides (D4)
| Field | Type | Notes |
|---|---|---|
| id | int10 PK | |
| cmid | int10 | unique |
| enabled | int1 | NULL/absent row = follow site `defaultenabled` (D8) |
| presentpct, latepct | int3 nullable | NULL = inherit site default |
| lategracemins | int4 nullable | |
| denominator | char(10) nullable | `scheduled` \| `actual` (B4.5) |
| trackedrole | — | not stored; capabilities are used instead (B6) |
| timemodified, usermodified | int10 | |

Site defaults live in `config_plugins` (`get_config('local_zoomattendance', ...)`): `defaultenabled` (0), `presentpct` (75), `latepct` (50), `lategracemins` (10), `denominator` (`scheduled`), `earlymarginmins` (30), `latemarginmins` (30), `clustergapmins` (30).

**Phase 2** (D6): `local_zoomattendance_idmap` (courseid, identitykey, userid, usermodified,
timemodified), unique (courseid, identitykey), for teacher-confirmed manual matching of unmatched participants.

## B4. Attendance calculation model

### B4.1 Inputs for occurrence `O = [S, E)`
All `zoom_meeting_participants` rows whose `detailsid` maps to `O` (B5 / B8). A user's
attendance is taken across **all sessions** mapped to `O`, because a host restart creates a
new UUID.

### B4.2 Identity and de-duplication
* Key = `u:<userid>` when `userid` is set, otherwise the `z:` key (B3).
* Phase 2 (D6): map `z:` keys to users through `local_zoomattendance_idmap` first.
* Drop exact duplicate segments per key: the same `(detailsid, join_time, leave_time)`
  (see the duplicate risk in A3).
* When the same `(detailsid, zoomuserid, join_time, leave_time)` appears with **and**
  without a userid, keep the matched one. This handles a later re-match.

### B4.3 Clip, union, sum (per identity)
```
intervals = [(max(j, S), min(l, E)) for (j, l) in segments if min(l, E) > max(j, S)]
sort intervals by start
merged = []
for (a, b) in intervals:
    if merged and a <= merged[-1].end:  merged[-1].end = max(merged[-1].end, b)
    else: merged.append((a, b))
attendedsecs = Σ (b - a) over merged
firstjoin    = merged[0].start   (null if empty)
```
* Zero-length segments (join == leave; these occur, see `tests/get_meeting_reports_test.php:279-281`)
  are dropped.
* `leave_time < join_time` counts as zero. This is defensive handling; the source data has
  no such constraint.
* Zoom's own `duration` column is **ignored**; only timestamps are used.

### B4.4 Percentage
`pct = attendedsecs / D × 100`, capped at 100, where `D` is the denominator (B4.5).
If `D <= 0`, the occurrence is flagged invalid and not graded.

### B4.5 Denominator (D5)
* `scheduled` (site default, overridable per activity): `D = E − S`.
* `actual`: `D = |[S, E) ∩ ⋃ session spans|`, i.e. only the time the meeting really ran
  inside the schedule. This avoids penalising students when the host starts late or ends
  early.

### B4.6 Status thresholds (D3)
Three settings:
* `presentpct` (default 75)
* `latepct` (default 50), must be ≤ `presentpct`
* `lategracemins` (default 10)

```
if no result row or pct < latepct:                               ABSENT
elif pct >= presentpct and firstjoin <= S + lategracemins*60:    PRESENT
else:                                                            LATE
```
So "late" means the person attended enough to count but either joined after the grace
period or stayed below the present threshold. Status is **computed at read time**, so
changing a threshold takes effect immediately without recomputation.

### B4.7 Where thresholds live (D4)
**Decision: both.** Site defaults (admin settings page) plus optional per-activity
overrides (`local_zoomattendance_setting`), editable on a plugin page linked from the activity's
secondary navigation (`activitysettings.php`). Using the page avoids injecting into
mod_zoom's form. The per-activity page also holds the enable toggle (D8) and the denominator
(D5). Rejected alternative: the core `coursemodule_standard_elements` /
`coursemodule_edit_post_actions` callbacks.

### B4.8 Expected users (who can be "absent")
Proposed rule, evaluated **live** for each occurrence `O` of activity `cm`:

A user is expected if all of the following hold:
1. **Active enrolment** in the course (`get_enrolled_with_capabilities_join($modcontext, '',
   'local/zoomattendance:betracked', $groupid, true)`, where `onlyactive = true` excludes
   suspended users and enrolments).
2. Has the capability **`local/zoomattendance:betracked`** in the module context (B6). This
   is how students and teachers are both supported, and how roles are excluded, through
   role definitions rather than hard-coded role names.
3. **Can access the activity**: `cm` visible to them and passes availability restrictions
   (`\core_availability\info_module::filter_user_list()`). If the activity uses group mode
   with a grouping, they must be in that grouping.
4. **Enrolment covered the occurrence**: some active `user_enrolments` row has
   `timestart <= O.timeend` and `timeend = 0 OR timeend >= O.timestart`. A student who
   enrolled in week 5 is not absent in week 1.

Known limitation: Moodle keeps only *current* enrolments. Someone unenrolled today drops out
of past occurrences' expected lists. Their result rows remain and are shown under
"attended, not currently expected".

### B4.9 Unmatched and non-expected participants
Each occurrence report has three buckets:
* **Expected users**: present / late / absent.
* **Matched but not expected**: a Moodle user who is not enrolled, lacks `betracked`, or
  cannot access the cm. Examples: guest lecturers, and users matched through step 5 of A4.
  Shown with duration, **no status**.
* **Unmatched Zoom participants** (`userid IS NULL`): grouped by identity key and shown with
  Zoom name, email (if the viewer may see it), duration and %. They never count towards
  statistics. In phase 2 (D6), a manager can map an unmatched identity to an expected user.
  The mapping is stored per course in `local_zoomattendance_idmap` and applies on the next recompute.

We also show a **weak-match badge** when `matchstrength = 1`: mod_zoom matched the user, but
the participant's email is not the user's email/API identifier. This mitigates the
name-prefix spoofing noted in A4. Weak matches are **flagged only**; they still count
normally (D7).

### B4.10 Worked example
Scheduled 10:00–11:00. Segments: 09:55–10:20, 10:15–10:30 (second device), 10:40–11:10.
Clipped: 10:00–10:20, 10:15–10:30, 10:40–11:00. Union: 10:00–10:30, 10:40–11:00, which is
50 min, 83 %. First join 10:00. Result: **PRESENT**. Naive summation of the raw durations
would give 25 + 15 + 30 = 70 min (117 %).

## B5. Sync task and duplicate/repeat safety

`local_zoomattendance\task\sync` (hourly):

1. **Snapshot occurrences** for every `zoom` row whose cm has attendance enabled:
   * non-recurring: upsert `('single', start_time, start_time + duration)`;
   * recurring with fixed time: upsert every `event` row (`modulename='zoom'`,
     `instance=zoomid`) by `occurrencekey = event.uuid`, with `timestart`,
     `timestart + timeduration`;
   * a **future** occurrence that disappears from `event` → `status = cancelled`;
     a **past** occurrence that disappears → left unchanged (A5 fragility).
   * Rescheduled occurrence (same uuid, new time) → update, but only while it is in the future.
2. **Detect changed sessions**: for each `zoom_meeting_details` row of an enabled activity,
   compute the fingerprint (one grouped SQL query over `zoom_meeting_participants`). Compare
   it with `local_zoomattendance_session.fingerprint`. New or changed → map to an occurrence (B8)
   and mark that occurrence dirty. Sessions that disappeared (mod_zoom reset or instance
   delete) → delete our session row and mark the occurrence dirty.
3. **Recompute dirty occurrences** (B4). Inside one transaction per occurrence, upsert
   results by `(occurrenceid, identitykey)` and delete result rows whose key no longer
   appears.
4. No watermark on time is needed. The fingerprint makes the task **idempotent**: re-runs,
   overlapping runs (guarded by a lock via `\core\lock\lock_config`), and mod_zoom
   re-imports converge to the same state.

Performance:
* Step 2 is one aggregate query per activity, using the `detailsid` foreign key index.
* Step 3 loads only the segments of dirty occurrences, ordered by `(userid, join_time)`
  through a recordset.
* Reports read `local_zoomattendance_result` plus one expected-users query per activity. Course
  overview pages batch every cm in one `IN()` query.
* Activities where the plugin is disabled are skipped entirely. Attendance is opt-in per
  activity; the site setting `defaultenabled` (off by default) makes it default-on (D8).

## B6. Capabilities

| Capability | Context | Archetypes (proposal) | Purpose |
|---|---|---|---|
| `local/zoomattendance:viewreports` | module | editingteacher, teacher, manager | View per-activity/per-course reports for everyone (group mode respected; `moodle/site:accessallgroups` to see all groups) |
| `local/zoomattendance:viewown` | module | student, teacher, editingteacher | View one's own attendance |
| `local/zoomattendance:betracked` | module | student only (D9); grant it to other roles to track them | Makes a user *expected* (B4.8) |
| `local/zoomattendance:manage` | module | editingteacher, manager | Per-activity thresholds, exclude an occurrence, manual matching, "recompute now". `riskbitmask: RISK_DATALOSS` if recompute/exclude can drop data |
| `local/zoomattendance:configure` | system | manager | (optional) site defaults. Normally `moodle/site:config` via admin settings is enough. |
| `local/zoomattendance:viewemail` | module | editingteacher, manager | See unmatched participants' emails (`RISK_PERSONAL`) |

Reports also honour mod_zoom's `zoom/maskparticipantdata` (`participants.php:53-61`). When it
is on, we show aggregates only (D10).

## B7. Security and privacy

* **Access checks**: `require_login($course, false, $cm)` then `require_capability(...)` on
  every page. Use `sesskey`/`require_sesskey()` for recompute, exclude and mapping actions.
  All output goes through `format_string()`/`s()`, because Zoom display names are user
  controlled. Exports use `\core\dataformat` (CSV/XLSX/ODS), with a capability check
  repeated in the download path.
* **Groups**: respect `groups_get_activity_groupmode()`. In separate groups mode without
  `accessallgroups`, only the viewer's groups are shown.
* **Spoofing**: the `(id)Name` prefix (A4) is shown as a weak match badge; weak matches are
  flagged, not excluded (D7).
* **Privacy API** (`classes/privacy/provider.php`), implementing
  `\core_privacy\local\metadata\provider`, `\core_privacy\local\request\plugin\provider`,
  `\core_privacy\local\request\core_userlist_provider`:
  * metadata: `local_zoomattendance_result` (userid, attendedsecs, firstjoin, lastleave, displayname),
    `local_zoomattendance_idmap` (userid, usermodified), `local_zoomattendance_setting.usermodified`.
    The table descriptions state that the data is derived from `mod_zoom`.
  * contexts: module contexts of the cms holding the user's result rows.
  * export: per cm, per occurrence, attended minutes, %, and status at export time.
  * delete: delete our rows for the user. **Caveat**: if mod_zoom's source rows survived, the
    next sync would re-create ours. In practice Moodle's privacy subsystem calls every
    provider (mod_zoom's and ours) for the same approved request, so the source rows go too
    (mod_zoom deletes by `userid`, `classes/privacy/provider.php:338`, `:380`). The
    `\core\event\user_deleted` observer additionally purges all rows for that user.
  * unmatched `displayname` has no userid, so it is covered with the cm by
    `delete_data_for_all_users_in_context`.
* **Data minimisation**: we store durations, not the raw segments (those stay in mod_zoom),
  and no emails. Unmatched identity keys are **hashed**.

## B8. Recurring-meeting handling

1. **Occurrence source**: our snapshot table, filled from `event` rows (A5) while they exist.
   Install/upgrade runs an initial snapshot immediately; the daily fragility makes early
   capture important.
2. **Session → occurrence mapping** (each `zoom_meeting_details` row):
   * candidates = occurrences of the same `zoomid` where
     `[details.start_time, details.end_time]` overlaps
     `[O.timestart − earlymarginmins, O.timeend + latemarginmins]` (admin settings, 30 / 30 min, D15);
   * pick the candidate with the **largest overlap**, and on a tie the nearest start;
   * a session can map to only one occurrence. An occurrence can have many sessions (host
     restarts).
3. **Sessions matching no occurrence** (ad-hoc start on an unscheduled day, or an occurrence
   whose event was deleted before our snapshot existed): create an `inferred` occurrence
   using the fallback below and flag it in the report as "unscheduled".
4. **Attendance is per occurrence**. Activity-level summaries aggregate across occurrences:
   the number present/late/absent, and mean % over non-excluded occurrences.
5. **Cancelled/excluded occurrences** (`status` 1/2) are shown but not counted.

### Fallback for meetings without a fixed schedule
Applies to `recurring = 1 AND recurrence_type = 0`, to sessions with no mapped occurrence,
and to cases where the snapshot is missing because the plugin was installed after the fact
(D11: no attempt to rebuild schedules from the `zoom` recurrence fields).
* **Cluster** the activity's sessions: sort by `start_time` and join consecutive sessions
  when the gap between one's `end_time` and the next `start_time` is ≤ `clustergapmins`
  (admin setting, default 30, D15).
* Each cluster becomes one `inferred` occurrence with window
  `[min(start_time), max(end_time)]`, which is the span the meeting actually ran (host
  presence as Zoom reported it).
* `occurrencekey = 's:' . sha1(first details uuid)` keeps it stable across re-runs.
* Late grace and thresholds apply as normal. The report labels these occurrences
  "inferred window".
* Teachers with `manage` can override an inferred window (`source = manual`) (D11).

## B9. Reports and per-user profile page

### Per-activity report (`/local/zoomattendance/report.php?id=<cmid>`)
Navigation: via `local_zoomattendance_extend_settings_navigation()` / secondary nav on the
Zoom activity, only when `viewreports` is held. The page does not replace mod_zoom's
Sessions page; it links to it.
* Header: activity name, thresholds in effect (site / overridden), last sync time,
  "Recompute now" (`manage`), and a warning when another `zoom` row shares this activity's
  `meeting_id` (A3, D13).
* **Occurrence list** (`core_table\flexible_table`/`\table_sql`): date/time, window source
  (scheduled/inferred), sessions mapped, expected / present / late / absent counts,
  unmatched count, excluded flag.
* **Occurrence detail** (`&occurrence=<id>`): the three buckets from B4.9. Columns: user,
  identity field (per `showuseridentity`), first join, last leave, attended (h:mm), %,
  status, match badge. Group selector. Export.
* **Matrix view** (optional tab): users × occurrences, cells P/L/A with %, a totals column,
  and export.

### Per-course summary (`/local/zoomattendance/course.php?id=<courseid>`)
Linked via `local_zoomattendance_extend_navigation_course()`. One row per user, one column
per enabled Zoom activity (aggregate % and P/L/A counts).

### Per-user page (`/local/zoomattendance/user.php?course=<id>&user=<id>`)
* Reached from the course participant profile with `core_myprofile` navigation
  (`local_zoomattendance_myprofile_navigation()`) and from the course summary.
* Viewer = self, which needs `viewown`; others need `viewreports` in the course (plus a
  group check).
* Content: every enabled Zoom activity in the course → every occurrence: date, window,
  attended, %, status. Totals per activity and for the course. For a teacher with
  `betracked`, the same view shows their own attendance.
* Students never see other users' data or unmatched participants.

## B10. Course reset, backup/restore, uninstall

* **Course reset**: observe `\core\event\course_reset_ended`. If mod_zoom's reset removed
  participant rows (A8), the next sync sees changed fingerprints and removes our results.
  To be explicit, the observer also deletes our results for the course's cms immediately.
  Occurrence snapshots are kept, since the schedule is not user data. No reset option of our
  own: our results follow mod_zoom's `reset_zoom_all` (D12), and always mirror the source
  data (D16).
* **Backup/restore**: **do not back up attendance results.** A restored Zoom activity gets a
  new `meeting_id` and no mod_zoom session data (A8), so restored results would be orphaned
  from their source and could not be recomputed or verified. Per-activity *settings*
  (thresholds) should be backed up through `backup_local_plugin`/`restore_local_plugin`
  at module level (`backup/moodle2/backup_local_zoomattendance_plugin.class.php`).
  **UNVERIFIED** until tested on Moodle 4.1 (D1): that this local-plugin backup hook runs at
  module level.
* **Activity/course deletion**: the `course_module_deleted` and `course_deleted` observers
  delete our rows (occurrences, sessions, results, settings, idmap).
* **Uninstall**: the tables are declared in `db/install.xml`, so Moodle drops them. The
  plugin removes its config and scheduled task automatically. `db/uninstall.php` has
  nothing to do.

## B11. Testing and coding standards

* **PHPUnit** (`tests/`), using `mod_zoom`'s generator (`tests/generator/lib.php` in
  mod_zoom) to create instances, and inserting details/participants directly:
  * calculator: clipping, union (overlapping, nested, adjacent, disjoint, zero-length,
    reversed), the B4.10 example, both denominators, threshold boundaries (exactly
    `presentpct`, exactly grace);
  * occurrence snapshot: past occurrence survives removal from `event`; future removal
    becomes cancelled; rescheduling;
  * session → occurrence mapping: restarts, ad-hoc session, NOTIME clustering;
  * expected users: suspended enrolment, enrolment start after the occurrence, missing
    `betracked`, availability restriction, separate groups;
  * idempotency: running sync twice gives an identical DB; duplicate source rows (A3) are
    not double counted;
  * privacy provider (`\core_privacy\tests\provider_testcase`);
  * observers (cm/course/user deleted, course reset).
* **Behat** (optional): report visibility per role, export link.
* **Standards**: `moodle-plugin-ci` (phpcs `moodle` standard, phpdoc, phplint, savepoints,
  mustache lint, grunt), frankenstyle naming, `classes/` autoloading, strings in
  `lang/en/local_zoomattendance.php`, output through renderers and Mustache templates, and
  the DB only through `$DB` with placeholders.

## B12. Proposed file layout (for later implementation)
```
version.php, settings.php, lib.php (navigation callbacks only)
db/access.php, db/install.xml, db/tasks.php, db/events.php, db/upgrade.php
classes/local/source/zoom_source.php        (all mod_zoom SQL)
classes/local/calculator.php                (clip/union/percent; pure, unit-testable)
classes/local/status.php                    (thresholds → P/L/A)
classes/local/occurrence_mapper.php
classes/local/expected_users.php
classes/task/sync.php
classes/observer.php
classes/privacy/provider.php
classes/output/*, templates/*
report.php, course.php, user.php, activitysettings.php
backup/moodle2/{backup,restore}_local_zoomattendance_plugin.class.php  (settings only)
lang/en/local_zoomattendance.php
tests/*
```

## B13. Phase 1 implementation notes

Phase 1 implements B1–B12 with decisions D1–D16, except for the items below.

* **When statuses are assigned.** An occurrence is only evaluated (present / late / absent)
  once at least one mod_zoom session is mapped to it. Before that it is shown as
  *Upcoming* (not ended yet) or *No session data* (ended, but mod_zoom has no session:
  the meeting did not happen or `get_meeting_reports` has not run yet). This stops
  everyone being marked absent during the up-to-6-hour gap before mod_zoom imports a
  report. Cancelled and excluded occurrences are shown but never counted.
* **Deferred to phase 2** (now done, see B14): manual matching (`local_zoomattendance_idmap`,
  D6) and manually overriding an inferred window (`source = manual`, second half of D11).
* **Capabilities:** `viewemail` and `configure` from B6 were dropped. Unmatched
  participants' emails are never displayed (only a hashed key is stored), and site
  defaults use the normal admin settings page.
* **Less personal data:** `local_zoomattendance_setting` does not store `usermodified`, so
  per-activity settings hold no personal data.
* **Grouping rule (B4.8 point 3):** access is decided by the activity's visibility and
  availability conditions (`\core_availability\info_module::filter_user_list()`), which
  already cover "grouping members only". Group mode alone does not remove anyone from the
  expected list; the group selector filters the report.
* **Masked participant data (D10):** when `zoom/maskparticipantdata` is on, the activity
  report shows only per-occurrence counts, and the occurrence detail, course summary and
  other users' pages are refused. A user can still see their own attendance.
* **Actual-time denominator:** stored per occurrence as `actualsecs` during recompute, so
  switching the denominator is a read-time change like the thresholds.
* **File layout:** the expected-user and evaluation logic is one read-side class
  (`classes/local/attendance.php`); effective settings are in `classes/local/settings.php`;
  the per-activity form is `classes/form/activitysettings.php`. The other files follow B12.
* **Testing:** 49 PHPUnit tests. Locally they ran on Moodle 5.0.10+, PHP 8.4, PostgreSQL 16
  with mod_zoom v5.5.1, together with Moodle's own privacy compliance tests.
  `.github/workflows/ci.yml` runs `moodle-plugin-ci` on Moodle 4.1 (PHP 8.0), 4.5
  (MariaDB) and 5.0.

## B14. Phase 2 implementation notes

Phase 2 completes D6 (manual matching) and the second half of D11 (manual windows).

* **Identity links (`local_zoomattendance_idmap`).** One row per course and `z:` identity key,
  pointing at an enrolled user, plus the Zoom name for reference. During recompute an
  unmatched segment whose key is linked counts as that user: its intervals join the user's
  own before clipping and union, so overlapping time is not double counted. The result's
  `matchstrength` is 3 ("Linked by teacher"). Removing a link restores the unmatched row.
* **Scope and capability.** Links apply to every Zoom activity in the course, so creating or
  removing one requires `local/zoomattendance:manage` in the **course** context. Only users
  enrolled in the course can be chosen.
* **Manual windows.** Only inferred (or already manual) occurrences can be edited. Scheduled
  windows keep coming from mod_zoom's calendar. Saving sets `source = manual` and a new
  `m:` key, so a later cluster of the same sessions can never collide with it. Manual
  occurrences are fixed for session mapping, like scheduled ones, and are never deleted by the
  sync. *Revert* deletes the manual occurrence, and the next sync infers it again.
* **Recompute guarantee.** Both changes set `timecomputed = 0` on the affected occurrences
  before syncing, and the sync always recomputes such occurrences. So a change made while
  another sync holds the lock still applies on the next scheduled sync.
* **Lifecycle and privacy.** Links are personal data in the **course** context: the privacy
  provider exports and deletes them there. They are removed when the user or the course is
  deleted, and on course reset only when mod_zoom's data is reset too (`reset_zoom_all`),
  since they describe that data. Other resets keep them.
* **Upgrade.** Version `2026100100` (0.2.0) creates the table in `db/upgrade.php`. Upgrading an
  existing 0.1.0 install keeps all stored results.

---

## Decisions

All open questions were resolved by adopting the proposed defaults.

| # | Topic | Decision |
|---|---|---|
| **D1** | Moodle version | **Moodle 4.1 LTS and later**, using legacy callbacks (`lib.php` navigation callbacks, `db/events.php` observers), no `\core\hook` API. Check each callback for deprecation when testing on the newest supported release. |
| **D2** | Minimum mod_zoom | `2026082400` (v5.5.1), the version analysed in Part A. |
| **D3** | Status semantics | B4.6 as written: ABSENT below `latepct`; PRESENT at or above `presentpct` and joined within `lategracemins`; otherwise LATE. Defaults 75 % / 50 % / 10 min. |
| **D4** | Where thresholds live | Both: site defaults plus optional per-activity overrides, edited on a plugin page (`activitysettings.php`), not in the Zoom activity form. |
| **D5** | Denominator | `scheduled` by default; per activity it can be switched to `actual`. |
| **D6** | Manual matching | Yes, in **phase 2** (`local_zoomattendance_idmap`). Phase 1 ships without it. |
| **D7** | Weak matches | Flagged with a badge, counted normally. |
| **D8** | Default enablement | Opt-in per activity; site setting `defaultenabled` (default off) makes it default-on. |
| **D9** | Tracked roles | `betracked` is given to the **student** archetype only. Teachers appear under "matched but not expected" unless an admin grants the capability. |
| **D10** | `maskparticipantdata` | Respected: when on, only aggregates are shown. |
| **D11** | Past occurrences lost before first snapshot | Inferred windows (B8 fallback) plus manual override. No rebuild from the recurrence fields. |
| **D12** | Course reset | Follow mod_zoom's reset; no reset option of our own. |
| **D13** | Shared `meeting_id` | Detect and show a warning in the activity report. |
| **D14** | Gradebook | Reports only; no grades written. |
| **D15** | Margins | Early/late mapping margins 30 / 30 min and cluster gap 30 min, all admin settings. |
| **D16** | Retention | Results always mirror the mod_zoom source; they are removed when the source rows go. |
