@block @block_aicourserecommender @javascript
Feature: Get AI course recommendations
  In order to find the right courses
  As a learner
  I answer some questions and get ranked courses with an explanation

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
    And I visit "/index.php?redirect=0"
    And I should see "Find the right courses for you in one minute" in the "AI course recommender" "block"
    And I should see "Question 1 of 4" in the "AI course recommender" "block"
    And I set the field with xpath "//textarea[@name='q1']" to "Primary school teacher"
    And I click on "Next" "button" in the "[data-region='question-card']:not([hidden])" "css_element"
    And I should see "Please accept the conditions to continue." in the "AI course recommender" "block"
    And I click on "I accept the conditions and I want personalised recommendations" "checkbox"
    And I click on "Next" "button" in the "[data-region='question-card']:not([hidden])" "css_element"
    And I should see "Question 2 of 4" in the "AI course recommender" "block"
    And I click on "Skip" "button" in the "[data-region='question-card']:not([hidden])" "css_element"
    And I set the field with xpath "//textarea[@name='q3']" to "Leadership, basic level"
    And I click on "Next" "button" in the "[data-region='question-card']:not([hidden])" "css_element"
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
    And I should see "Your interests" in the "AI course recommender" "block"
    And I should see "Primary school teacher" in the "AI course recommender" "block"
    And I should see "No answer yet" in the "AI course recommender" "block"
    And I click on "Edit" "button" in the "[data-region='summary-item'][data-slot='1']" "css_element"
    And I set the field with xpath "//li[@data-slot='1']//textarea" to "Secondary school teacher"
    And I press "Save and update"
    And I should see "Courses for you" in the "AI course recommender" "block"
    And I press "Change my interests"
    And I should see "Secondary school teacher" in the "AI course recommender" "block"

  Scenario: Read the conditions in a dialogue
    Given I press "Change my interests"
    And I press "Delete my data"
    And I click on "Delete" "button" in the ".modal-dialog" "css_element"
    When I press "Read the conditions"
    Then I should see "How your data is used" in the ".modal-dialog" "css_element"
    And I should see "Your name, email, username and other identifiers are never sent" in the ".modal-dialog" "css_element"

  Scenario: Enrol from a recommendation
    When I click on "[data-itemtype='course'] [data-action='enrol']" "css_element"
    And I click on "Enrol me" "button" in the ".modal-dialog" "css_element"
    Then I should see "You are enrolled in the course"
