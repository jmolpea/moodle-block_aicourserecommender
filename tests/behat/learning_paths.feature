@block @block_aicourserecommender @javascript
Feature: Learning paths
  In order to follow a sequence of courses
  As a manager I create learning paths with AI help
  and as a learner I see the path, my progress and enrol in the whole path

  Background:
    Given the following config values are set as admin:
      | behatfakeai | 1 | block_aicourserecommender |
    # Moodle 5.2+ only shows the site home to logged-in users when it is enabled.
    And the following config values are set as admin:
      | enablemyhome | 1 |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
    And the following "user preferences" exist:
      | user     | preference        | value |
      | student1 | drawer-open-block | 1     |
    And the following "block_aicourserecommender > candidate courses" exist:
      | shortname | fullname      |
      | C1        | Path course 1 |
      | C2        | Path course 2 |
    And the following "courses" exist:
      | fullname      | shortname |
      | Path course 3 | C3        |

  Scenario: Create a learning path with an AI description
    Given I log in as "admin"
    And I visit "/blocks/aicourserecommender/managepaths.php"
    And I click on "Add learning path" "link"
    And I set the following fields to these values:
      | Title   | Data leadership              |
      | Courses | Path course 2, Path course 1 |
    When I press "Generate description with AI"
    And I click on "Accept and continue" "button" in the ".modal-dialog" "css_element"
    And I click on "Apply" "button" in the ".modal-dialog" "css_element"
    And I press "Save changes"
    Then I should see "Learning path saved."
    And I should see "Data leadership"
    And I click on "Data leadership" "link"
    And I should see "Generated description (en)"
    And "Path course 2" "text" should appear before "Path course 1" "text"

  Scenario: Learner sees the recommended path and enrols in the whole path
    Given the following "block_aicourserecommender > paths" exist:
      | name            | courses    |
      | Data leadership | C1, C2, C3 |
    And the following "blocks" exist:
      | blockname           | contextlevel | reference | pagetypepattern | defaultregion |
      | aicourserecommender | System       | 1         | site-index      | side-pre      |
    And the following config values are set as admin:
      | requireconsent | 0 | block_aicourserecommender |
    And I log in as "student1"
    And I visit "/index.php?redirect=0"
    And I click on "I accept the conditions and I want personalised recommendations" "checkbox"
    And I click on "Next" "button" in the "[data-region='question-card']:not([hidden])" "css_element"
    And I set the field with xpath "//textarea[@name='q2']" to "Lead a data team"
    And I click on "Next" "button" in the "[data-region='question-card']:not([hidden])" "css_element"
    And I click on "Skip" "button" in the "[data-region='question-card']:not([hidden])" "css_element"
    And I press "Get my recommendations"
    And I should see "Learning paths for you" in the "AI course recommender" "block"
    When I click on "View path" "link" in the "AI course recommender" "block"
    Then I should see "Data leadership"
    And I should see "0 of 3 courses completed"
    And I should see "Not available now"
    And I should see "This course does not allow self enrolment."
    And I press "Enrol me in the whole path"
    And I should see "You will be enrolled in 2 course(s)." in the ".modal-dialog" "css_element"
    And I should see "These courses cannot be enrolled in automatically:" in the ".modal-dialog" "css_element"
    And I should see "Path course 3" in the ".modal-dialog" "css_element"
    And I click on "Enrol me" "button" in the ".modal-dialog" "css_element"
    And I should see "Enrolment result"
    And I should see "You have been enrolled in 2 course(s)."
    And I should see "Some courses could not be enrolled in automatically."
    And I should see "Not enrolled. This course does not allow self enrolment."
