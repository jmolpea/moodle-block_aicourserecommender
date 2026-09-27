@block @block_aicourserecommender @javascript
Feature: Get AI course recommendations
  In order to find the right courses
  As a learner
  I answer some questions and get ranked courses with an explanation

  Background:
    Given the following config values are set as admin:
      | behatfakeai | 1 | block_aicourserecommender |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
    And the following "user preferences" exist:
      | user     | preference        | value |
      | student1 | drawer-open-block | 1     |
    And the following "block_aicourserecommender > candidate courses" exist:
      | shortname | fullname           |
      | C1        | Excel for managers |
      | C2        | Leadership basics  |
      | C3        | Data analysis      |
      | C4        | Public speaking    |
      | C5        | Project management |
      | C6        | Negotiation skills |
    And the following "blocks" exist:
      | blockname           | contextlevel | reference | pagetypepattern | defaultregion |
      | aicourserecommender | System       | 1         | site-index      | side-pre      |
    And I log in as "student1"
    And I am on site homepage
    And I click on "I have read the notice and I want personalised recommendations" "checkbox"
    And I press "Start"
    And I press "Accept and continue"
    And I set the field with xpath "//textarea[@name='q1']" to "Primary school teacher"
    And I set the field with xpath "//textarea[@name='q3']" to "Leadership, basic level"
    And I press "Get my recommendations"

  Scenario: Questionnaire, results in two sections and see more
    Then I should see "Courses for you" in the "AI course recommender" "block"
    And I should see "Why we recommend it:" in the "AI course recommender" "block"
    And "[data-region='list-course'] li" "css_element" should exist
    And I should not see "Negotiation skills" in the "AI course recommender" "block"
    And I press "See more courses"
    And I should see "Negotiation skills" in the "AI course recommender" "block"
    And "See more courses" "button" should not be visible

  Scenario: Rate a recommendation and change the interests
    When I click on "[data-itemtype='course'] [data-rating='-1']" "css_element"
    And I set the field with xpath "//input[@data-region='reason-input']" to "Too advanced for me"
    And I press "Send"
    Then I should see "Thanks, we will take it into account."
    And I press "Change my interests"
    And the field with xpath "//textarea[@name='q1']" matches value "Primary school teacher"
    And I set the field with xpath "//textarea[@name='q1']" to "Secondary school teacher"
    And I press "Get my recommendations"
    And I should see "Courses for you" in the "AI course recommender" "block"

  Scenario: Enrol from a recommendation
    When I click on "[data-itemtype='course'] [data-action='enrol']" "css_element"
    And I click on "Enrol me" "button" in the ".modal-dialog" "css_element"
    Then I should see "You are enrolled in the course"
