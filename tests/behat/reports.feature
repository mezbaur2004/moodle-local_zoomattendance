@local @local_zoomattendance
Feature: Zoom attendance reports
  In order to know who attended the Zoom classes
  As a teacher or a student
  I need to see attendance per student and per class

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Tess      | Teacher  |
      | student1 | Amy       | Student  |
      | student2 | Ben       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following config values are set as admin:
      | defaultenabled | 1 | local_zoomattendance |
    And the Zoom activity "Physics" in course "C1" had a class 2 days ago attended by:
      | user     | minutes |
      | student1 | 60      |
      | student2 | 40      |

  Scenario: A teacher sees each student and the class headcount
    Given I log in as "teacher1"
    When I am on the Zoom attendance page of course "C1"
    Then I should see "Amy Student"
    And I should see "Ben Student"
    And I should see "2 of 2 present"
    And I should see "1 present + 1 partial · 0 absent"

  Scenario: Excluding a class needs a reason, which the report then shows
    Given I log in as "teacher1"
    And I am on the Zoom attendance report of "Physics"
    When I click on "Exclude" "link"
    And I press "Exclude"
    Then I should see "Required"
    And I set the field "Reason" to "Public holiday"
    And I press "Exclude"
    And I should see "Class excluded."
    And I should see "Public holiday"
    And I should see "Include"

  Scenario: A student sees only their own attendance
    Given I log in as "student1"
    When I am on my Zoom attendance page of course "C1"
    Then I should see "Present"
    And I should see "100.0%"
    And I should not see "Ben Student"

  Scenario: While teacher attendance is tracked, only managers set class windows
    Given the following config values are set as admin:
      | teachertracking | 1 | local_zoomattendance |
    And the Zoom activity "Tutorial" in course "C1" without a fixed time had a class 3 days ago attended by:
      | user     | minutes |
      | teacher1 | 30      |
    When I log in as "teacher1"
    And I am on the Zoom attendance report of "Tutorial"
    Then I should see "Inferred from sessions"
    And I should not see "Set window"
    And I log out
    And I log in as "admin"
    And I am on the Zoom attendance report of "Tutorial"
    And I should see "Set window"
