@bbbext @bbbext_advgrd
Feature: Teachers can configure advanced grading on a BigBlueButton activity
  In order to assess class participation against a defensible framework
  As a teacher
  I need to enable advanced grading on a BigBlueButton activity, import a starter template, and map criteria to engagement metrics

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activity" exists:
      | activity     | bigbluebuttonbn  |
      | course       | C1               |
      | name         | Live class       |
      | grade        | 100              |
      | idnumber     | livec1           |

  @javascript
  Scenario: Teacher selects rubric grading and reaches the templates picker via secondary nav
    Given I log in as "teacher1"
    And I am on the "livec1" Activity page
    And I navigate to "Settings" in current page administration
    When I expand all fieldsets
    And I set the field "Grading method" to "Rubric"
    And I press "Save and return to course"
    And I am on the "livec1" Activity page
    And I navigate to "Import template" in current page administration
    Then I should see "Community of Inquiry"
    And I should see "Quantity + Quality"
    And I should see "Inclusive (multi-modal)"

  Scenario: Teacher cannot enable advanced grading without setting a maximum grade
    Given the following "activity" exists:
      | activity     | bigbluebuttonbn |
      | course       | C1              |
      | name         | Ungraded class  |
      | grade        | 0               |
      | idnumber     | ungraded        |
    And I log in as "teacher1"
    And I am on the "ungraded" Activity page
    And I navigate to "Settings" in current page administration
    When I expand all fieldsets
    And I set the field "Grading method" to "Rubric"
    And I press "Save and return to course"
    Then I should see "Set a numeric maximum grade for this activity"
