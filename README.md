Webform Waitlist 8.x
---------------

### About this Module

The Webform Waitlist module adds a handler that provides waitlist functionality for a Webform that is attached to a Node.

The primary use case for this module is to:

- **Add** a handler to a webform node that enables submissions to be waitlisted.
- **View** results using Webform Views and edit the waitlisted status using the Views Bulk Operations Field.

### Goals

- A handler that, when added to a webform, sets a submission to that form
  to a waitlisted status if the maximum number of submissions has been met.
  Also enables editing of waitlisted status using Webform Views.


### Installing the Webform Module

1. Ensure Webform, Webform Node and Webform Views are already installed and enabled. Copy/upload the Webform Waitlist 
   module to the modules directory of your Drupal installation.

2. Enable the 'Webform Waitlist' module.

3. Add three fields to the content type that the webform will be attached to: a Boolean, An Integer,
   and and a Textfield. Ex.) field_webform_waitlist, field_webform_waitlist_threshold, field_webform_waitlist_notice.

4. Add a "Message" type element to the webform you will add the handler to. Make sure to name it "waitlist_message".
   
5. Add the "Waitlist Handler" to the webform and copy/paste the following in the custom settings (YAML) section,  
   replacing the names of the node fields with your field names from Step 3:

    waitlist: '[webform_submission:node:field_webform_waitlist]'
    waitlist_threshold: '[webform_submission:node:field_webform_waitlist_threshold]'
    waitlist_notice: '[webform_submission:node:field_webform_waitlist_notice]'

6. Add the fields "Waitlist" and "Webform submission operations bulk form" to the Webform Submission View. 

### Project Status

- In the future, add general settings to make the module work on a webform that is not attached to a node,
  and add the waitlisted status to the webform columns so it is visible without Webform Views.

### Similar Modules

- Webform Waitlist module for Drupal 7 provides a similar handler. It does not work on webform nodes.
