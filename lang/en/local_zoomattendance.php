<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * English strings for local_zoomattendance.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['attendancereport'] = 'Zoom attendance report';
$string['attendancesettings'] = 'Zoom attendance settings';
$string['attended'] = 'Attended';
$string['attendedminutes'] = 'Attended (minutes)';
$string['backtooccurrences'] = 'Back to all occurrences';
$string['backtoreport'] = 'Back to the attendance report';
$string['clustergapmins'] = 'Session gap for unscheduled meetings (minutes)';
$string['clustergapmins_desc'] = 'For meetings without a fixed schedule, sessions separated by at most this many minutes are treated as one occurrence.';
$string['courseattendance'] = 'Zoom attendance';
$string['courselinks'] = 'Links in this course';
$string['coursesummary_help'] = 'Each cell shows present / late / absent counts and the mean attendance percentage over the occurrences that have Zoom session data.';
$string['defaultenabled'] = 'Track attendance by default';
$string['defaultenabled_desc'] = 'If enabled, attendance is tracked for every Zoom activity unless a teacher turns it off. Otherwise it must be turned on per activity.';
$string['denominator'] = 'Measure attendance against';
$string['denominator_actual'] = 'Time the meeting actually ran';
$string['denominator_desc'] = 'The scheduled length of each occurrence, or only the part of the schedule during which the Zoom meeting was actually running.';
$string['denominator_help'] = 'The attendance percentage is attended time divided by this length. "Time the meeting actually ran" avoids penalising participants when the host starts late or ends early.';
$string['denominator_scheduled'] = 'Scheduled length';
$string['denominatorinfo'] = 'Percentages are measured against {$a}.';
$string['earlymarginmins'] = 'Early margin (minutes)';
$string['earlymarginmins_desc'] = 'A Zoom session starting up to this many minutes before a scheduled occurrence is still matched to it.';
$string['enabled'] = 'Track attendance';
$string['errorgrace'] = 'Enter a whole number of minutes between 0 and 1440, or leave empty to use the site default.';
$string['errorlateabovepresent'] = 'The late threshold cannot be higher than the present threshold.';
$string['errornotenrolled'] = 'Choose a user enrolled in this course.';
$string['errornotinferred'] = 'Only occurrences inferred from Zoom sessions can have their window set. Scheduled windows come from the Zoom activity.';
$string['erroroneuser'] = 'Choose exactly one user.';
$string['errorwindowlength'] = 'The window cannot be longer than 24 hours.';
$string['errorwindoworder'] = 'The window must end after it starts.';
$string['exclude'] = 'Exclude';
$string['expected'] = 'Expected';
$string['expectedusers'] = 'Expected participants';
$string['firstjoin'] = 'First join';
$string['identitylinks'] = 'Zoom identity links';
$string['include'] = 'Include';
$string['inherit'] = 'Site default ({$a})';
$string['invalidaction'] = 'Invalid action.';
$string['invalididentity'] = 'This Zoom participant was not found in this course.';
$string['invalidoccurrence'] = 'This occurrence does not belong to the activity.';
$string['lastcomputed'] = 'Last computed: {$a}';
$string['lastleave'] = 'Last leave';
$string['lategracemins'] = 'Late after (minutes)';
$string['lategracemins_desc'] = 'Participants who first join more than this many minutes after the scheduled start are late, even with enough attended time.';
$string['lategracemins_help'] = 'Participants who first join more than this many minutes after the start are marked late, even if they attended long enough to be present. Leave empty to use the site default.';
$string['latemarginmins'] = 'Late margin (minutes)';
$string['latemarginmins_desc'] = 'A Zoom session ending up to this many minutes after a scheduled occurrence is still matched to it.';
$string['latepct'] = 'Late threshold';
$string['latepct_desc'] = 'Minimum attendance percentage to count as late. Below it a participant is absent.';
$string['latepct_help'] = 'Minimum attendance percentage to be marked late. Participants below it are absent.';
$string['linked'] = 'Participant linked. Attendance recomputed.';
$string['linkedon'] = 'Linked on';
$string['linkintro'] = 'Choose the Moodle user this Zoom participant really is. The link applies to every Zoom activity in this course, for past and future sessions.';
$string['linktouser'] = 'Link to user';
$string['linkuser'] = 'Moodle user';
$string['linkuser_help'] = 'The participant\'s time is added to this user\'s attendance, merged with any time the Zoom plugin already matched to them. Remove the link to undo.';
$string['manualmatch'] = 'Linked by teacher';
$string['manualmatch_help'] = 'Part of this time comes from a Zoom participant a teacher linked to this user.';
$string['maskedinfo'] = 'Participant data is masked by the Zoom plugin settings, so only aggregate figures are shown.';
$string['matching'] = 'Session matching';
$string['matching_desc'] = 'How actual Zoom sessions are matched to scheduled occurrences.';
$string['myattendance'] = 'My Zoom attendance';
$string['noactivities'] = 'No Zoom activities in this course track attendance.';
$string['nolinks'] = 'No Zoom participants have been linked in this course.';
$string['nooccurrences'] = 'No occurrences yet. They appear after the next sync, or after a recompute.';
$string['notenabled'] = 'Attendance tracking is turned off for this activity. Existing data is shown but not updated.';
$string['notexpected'] = 'Not expected';
$string['notexpected_help'] = 'Moodle users who joined but are not expected: not enrolled, not tracked, or unable to access the activity. They have no status.';
$string['notexpectedusers'] = 'Matched but not expected';
$string['nouserdata'] = 'No Zoom attendance to show.';
$string['nousers'] = 'Nobody to show.';
$string['occurrence'] = 'Occurrence';
$string['participantgroup'] = 'List';
$string['percentage'] = 'Attendance';
$string['pluginname'] = 'Zoom attendance';
$string['presentpct'] = 'Present threshold';
$string['presentpct_desc'] = 'Minimum attendance percentage to count as present, when the participant also joined within the late period.';
$string['presentpct_help'] = 'Minimum attendance percentage to be marked present. The participant must also have joined within the late period.';
$string['privacy:metadata:idmap'] = 'Links a teacher made from an unmatched Zoom participant to a Moodle user, per course.';
$string['privacy:metadata:idmap:displayname'] = 'The Zoom display name of the linked participant.';
$string['privacy:metadata:idmap:timecreated'] = 'When the link was made.';
$string['privacy:metadata:idmap:userid'] = 'The Moodle user the Zoom participant was linked to.';
$string['privacy:metadata:result'] = 'Attended time per Zoom occurrence, derived from the participant reports stored by the Zoom activity.';
$string['privacy:metadata:result:attendedsecs'] = 'Attended time inside the scheduled window, in seconds.';
$string['privacy:metadata:result:displayname'] = 'The Zoom display name of a participant who could not be matched to a Moodle user.';
$string['privacy:metadata:result:firstjoin'] = 'First time the participant was in the meeting within the window.';
$string['privacy:metadata:result:lastleave'] = 'Last time the participant was in the meeting within the window.';
$string['privacy:metadata:result:matchstrength'] = 'Whether the Zoom participant was matched to the user by email or by a weaker method.';
$string['privacy:metadata:result:userid'] = 'The Moodle user the Zoom participant was matched to.';
$string['recompute'] = 'Recompute now';
$string['recomputebusy'] = 'A sync for this activity is already running. Try again shortly.';
$string['recomputed'] = 'Attendance recomputed.';
$string['revertwindow'] = 'Revert to the window inferred from sessions';
$string['savewindow'] = 'Save window';
$string['sessions'] = 'Zoom sessions';
$string['setwindow'] = 'Set window';
$string['setwindowintro'] = 'Current window: {$a->window} ({$a->source}). Participants\' time is clipped to this window and percentages are measured against it.';
$string['sharedmeetingid'] = '{$a} other Zoom activities use the same Zoom meeting. The Zoom plugin attaches session data to only one of them, so this report may be empty or incomplete.';
$string['source'] = 'Source';
$string['source_inferred'] = 'Inferred from sessions';
$string['source_manual'] = 'Set by teacher';
$string['source_schedule'] = 'Scheduled';
$string['state'] = 'State';
$string['status'] = 'Status';
$string['status_absent'] = 'Absent';
$string['status_cancelled'] = 'Cancelled';
$string['status_evaluated'] = 'Evaluated';
$string['status_excluded'] = 'Excluded';
$string['status_invalid'] = 'No valid length';
$string['status_late'] = 'Late';
$string['status_nodata'] = 'No session data';
$string['status_present'] = 'Present';
$string['status_upcoming'] = 'Upcoming';
$string['summarycell'] = '{$a->present} / {$a->late} / {$a->absent} ({$a->pct})';
$string['tasksync'] = 'Sync Zoom attendance';
$string['thresholds'] = 'Attendance thresholds';
$string['thresholds_desc'] = 'Site defaults. Teachers can override them per activity.';
$string['thresholdsinfo'] = 'Present from {$a->present}% (joined within {$a->grace} minutes), late from {$a->late}%, otherwise absent. Measured against: {$a->denominator}.';
$string['unlink'] = 'Remove link';
$string['unlinked'] = 'Link removed. Attendance recomputed.';
$string['unmatched'] = 'Unmatched';
$string['unmatched_help'] = 'Zoom participants the Zoom plugin could not match to a Moodle user. They do not count in the totals.';
$string['unmatchedparticipants'] = 'Unmatched Zoom participants';
$string['userattendance'] = 'Zoom attendance: {$a}';
$string['usersummary'] = 'Present: {$a->present}, late: {$a->late}, absent: {$a->absent}.';
$string['weakmatch'] = 'Weak match';
$string['weakmatch_help'] = 'The Zoom plugin matched this participant without an email match (for example by name). Check that it is the right person.';
$string['windowend'] = 'Window end';
$string['windowreverted'] = 'Window reverted. It will be inferred from the Zoom sessions again.';
$string['windowsaved'] = 'Window saved. Attendance recomputed.';
$string['windowstart'] = 'Window start';
$string['windowstart_help'] = 'The time the class was meant to start. Joining more than the late period after it counts as late.';
$string['zoomattendance'] = 'Zoom attendance';
$string['zoomattendance:betracked'] = 'Be expected in Zoom attendance';
$string['zoomattendance:manage'] = 'Manage Zoom attendance settings';
$string['zoomattendance:viewown'] = 'View own Zoom attendance';
$string['zoomattendance:viewreports'] = 'View Zoom attendance reports';
$string['zoomname'] = 'Zoom name';
$string['zoomsessionsreport'] = 'Zoom sessions report';
