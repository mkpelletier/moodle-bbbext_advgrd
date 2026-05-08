@bbbext @bbbext_advgrd
Feature: Teachers can grade BigBlueButton participation against the imported rubric and see engagement evidence
  In order to ground subjective judgement in observable signals
  As a teacher
  I need an evidence panel of the student's BigBlueButton engagement metrics alongside the rubric grading form

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | student1 | Student   | One      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activity" exists:
      | activity     | bigbluebuttonbn |
      | course       | C1              |
      | name         | Live class      |
      | grade        | 100             |
      | idnumber     | livec1          |
    And the Community of Inquiry rubric template has been imported into "Live class"
    And the user "student1" has BigBlueButton engagement evidence in "Live class":
      | metric    | value |
      | duration  | 2700  |
      | talks     | 480   |
      | chats     | 9     |
      | raisehand | 4     |
      | polls     | 2     |
      | emojis    | 6     |

  @javascript
  Scenario: Teacher opens the grading list and grades a student with evidence visible
    Given I log in as "teacher1"
    And I am on the "livec1" Activity page
    When I navigate to "Participation grading" in current page administration
    Then I should see "Student One"
    When I click on "Grade" "link" in the "Student One" "table_row"
    Then I should see the engagement evidence panel
    And I should see "Suggested levels"
    And I should see "Cognitive presence — Open communication"
