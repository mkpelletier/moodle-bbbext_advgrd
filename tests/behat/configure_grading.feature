@bbbext @bbbext_advgrd
Feature: Teachers can configure advanced grading on a BigBlueButton activity
  In order to assess class participation against a defensible framework
  As a teacher
  I need to enable advanced grading on a BigBlueButton activity, import the Community of Inquiry rubric template, and map criteria to engagement metrics

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
  Scenario: Teacher selects rubric grading and imports the CoI template
    Given I log in as "teacher1"
    And I am on the "Live class" Activity page
    And I navigate to "Settings" in current page administration
    When I expand all fieldsets
    And I set the field "Grading method" to "Rubric"
    And I press "Save and return to course"
    And I am on the "Live class" Activity page
    And I follow "Configure rubric and metric mappings"
    And I press "Import Community of Inquiry template"
    Then I should see "Cognitive presence — Triggering events"
    And I should see "Social presence — Open communication"
    And I should see "Teaching presence — Facilitation of discourse"
    And I should see "Save mappings"

  Scenario: Teacher cannot enable advanced grading without setting a maximum grade
    Given the following "activity" exists:
      | activity     | bigbluebuttonbn |
      | course       | C1              |
      | name         | Ungraded class  |
      | grade        | 0               |
      | idnumber     | ungraded        |
    And I log in as "teacher1"
    And I am on the "Ungraded class" Activity page
    And I navigate to "Settings" in current page administration
    When I expand all fieldsets
    And I set the field "Grading method" to "Rubric"
    And I press "Save and return to course"
    Then I should see "Set a numeric maximum grade for this activity"
