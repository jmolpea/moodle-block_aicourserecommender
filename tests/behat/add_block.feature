@block @block_aicourserecommender
Feature: Add the AI course recommender block
  In order to recommend courses with AI
  As an administrator
  I can add the block to the front page and the Dashboard only

  Background:
    Given the following config values are set as admin:
      | behatfakeai | 1 | block_aicourserecommender |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |

  @javascript
  Scenario: Add the block to the front page and the Dashboard
    Given I log in as "admin"
    And I am on site homepage
    And I turn editing mode on
    When I add the "AI course recommender" block
    Then I should see "AI course recommender"
    And I follow "Dashboard"
    And I add the "AI course recommender" block
    And I should see "AI course recommender"

  @javascript
  Scenario: The block cannot be added to a course
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    When I click on "Add a block" "link"
    Then I should not see "AI course recommender"

  Scenario: Guests do not see the block
    Given the following "blocks" exist:
      | blockname           | contextlevel | reference | pagetypepattern | defaultregion |
      | aicourserecommender | System       | 1         | site-index      | side-pre      |
    When I am on site homepage
    Then I should not see "AI course recommender"
