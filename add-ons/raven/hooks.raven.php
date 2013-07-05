<?php

class Hooks_raven extends Hooks {

  /**
   * Process a form submission
   *
   * @return void
   **/
  public function raven__process() {

    /*
    |--------------------------------------------------------------------------
    | Prep form and handler variables
    |--------------------------------------------------------------------------
    |
    | We're going to assume success = true here to simplify code readability.
    | Checks already exist for require and validation so we simply flip the
    | switch there.
    |
    */
    $success = true;
    $errors = array();

    # Pull out any hidden fields intended to help processing

    /*
    |--------------------------------------------------------------------------
    | Hidden fields and $_POST hand off
    |--------------------------------------------------------------------------
    |
    | We slide the hidden key out of the POST data and assign the rest to a
    | cleaner $submission variable.
    |
    */

    $hidden = $_POST['hidden'];
    unset($_POST['hidden']);
    $submission = $_POST;

    /*
    |--------------------------------------------------------------------------
    | Grab formset and collapse settings
    |--------------------------------------------------------------------------
    |
    | Formset settings are merged on top of the default raven.yaml config file
    | to allow per-form overrides.
    |
    */
    $formset = Yaml::parse('_config/add-ons/raven/formsets/' . $hidden['formset'] . '.yaml');
    $config  = array_merge($this->config, $formset, array('formset' => $hidden['formset']));

   /*
    |--------------------------------------------------------------------------
    | Prep filters
    |--------------------------------------------------------------------------
    |
    | We jump through some PHP hoops here to filter, sanitize and validate
    | our form inputs.
    |
    */

    $allowed_fields   = array_flip(array_get($formset, 'allowed', array()));
    $required_fields  = array_flip(array_get($formset, 'required', array()));
    $validation_rules = isset($formset['validate']) ? $formset['validate'] : array();
    $messages         = isset($formset['messages']) ? $formset['messages'] : array();
    $return           = isset($hidden['return']) ? $hidden['return'] : Config::getSiteRoot();

    /*
    |--------------------------------------------------------------------------
    | Allowed fields
    |--------------------------------------------------------------------------
    |
    | It's best to only allow a set of predetermined fields to cut down on
    | spam and misuse.
    |
    */

    if (count($allowed_fields) > 0) {
      $submission = array_intersect_key($submission, $allowed_fields);
    }

    /*
    |--------------------------------------------------------------------------
    | Required fields
    |--------------------------------------------------------------------------
    |
    | Required fields aren't required (ironic-five!), but if any are set
    | and missing from the POST, we'll be squashing this submission right here
    | and sending back an array of missing fields.
    |
    */

    if (count($required_fields) > 0) {
      $missing = array_flip(array_diff_key($required_fields, array_filter($submission)));

      if (count($missing) > 0) {
        foreach ($missing as $key => $field) {
          $errors['missing'][] = array(
            'field' => $field
          );
        }
        $success = false;
      }
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    |
    | Run optional per-field validation. Any data failing the specified
    | validation rule will squash the submission and send back error messages
    | as specified in the formset.
    |
    */

    $invalid = $this->validate($submission, $validation_rules);

    # Prepare a data array of fields and error messages use for template display
    if (count($invalid) > 0) {

      $errors['invalid'] = array();
      foreach ($invalid as $field) {
        $errors['invalid'][] = array(
          'field' => $field,
          'message' => isset($messages[$field]) ? $messages[$field] : null
        );
      }
      $success = false;
    }

    /*
    |--------------------------------------------------------------------------
    | Hook: Pre Process
    |--------------------------------------------------------------------------
    |
    | Allow pre-processing by other add-ons with the ability to kill the
    | success of the submission. Has access to the submission and config.
    |
    */

    $success = Hook::run('raven', 'pre_process', $success, array(
      'submission' => $submission,
      'config' => $config)
    );

    /*
    |--------------------------------------------------------------------------
    | Finalize & determine action
    |--------------------------------------------------------------------------
    |
    | Send back the errors if validation or require fields are missing.
    | If successful, save to file (if enabled) and send notification
    | emails (if enabled).
    |
    */

    if ($success) {

      Session::setFlash('raven', array('success' => true));
      # Shall we save?
      if (array_get($config, 'submission_save_to_file', false) === true) {
        $this->save($submission, $config['submission_save_path'], array_get($config, 'file_prefix', ''));
      }

      # Shall we send?
      if (array_get($config, 'send_notification_email', false) === true) {
        $this->send($submission, $config);
      }

      /*
      |--------------------------------------------------------------------------
      | Hook: On Success
      |--------------------------------------------------------------------------
      |
      | Allow events after the form as been processed. Has access to the
      | submission and config.
      |
      */

      Hook::run('raven', 'on_success', null, null, array(
        'submission' => $submission,
        'config' => $config)
      );

      # Shall we...dance?
      URL::redirect(URL::format($return));

    } else {
      $errors['success'] = false;

      Session::setFlash('raven', $errors);
      URL::redirect(URL::format($return));
    }
  }

  /**
   * Loop through fields and filter them through individual validation rules
   *
   * @return array
   **/
  private function validate($fields, $rules) {
    $invalid = array();
    foreach ($rules as $key => $rule) {
      if (isset($fields[$key])) {
        if ( ! $this->handleValidationRule($fields[$key], $rules[$key])) {
          $invalid[] = $key;
        }
      }
    }
    return $invalid;
  }

  /**
   * Smart method to process fields, regardless of data type
   *
   * @return bool
   **/
  private function handleValidationRule($field, $rule)
  {
    if ($field == '') return true; # only validate non-empty fields.

    $spawn = new v;
    if ( ! is_array($rule)) {
      $spawn->addRule(v::buildRule($rule));
    } else {
      foreach ($rule as $rule => $params) {
        $params = ! is_array($params) ? (array) $params : $params; # make sure params are an array
        $spawn->addRule(v::buildRule($rule, $params));
      }
    }

    return $spawn->validate($field);
  }

  /**
   * Save submission to file
   *
   * @return void
   **/
  private function save($data, $location, $prefix = '')
  {
    if (array_get($this->config, 'master_killswitch')) return;

    $EXT = array_get($this->config, 'submission_save_extension', 'yaml');

    if ( ! File::exists($location)) {
      Folder::make($location);
    }

    $prefix = $prefix != '' ? $prefix . '-' : $prefix;

    $filename = $location . $prefix . date('Y-m-d-Gi-s', time());

    # Ensure a unique filename in the event two forms are submitted in the same second
    if (File::exists($filename . '.' . $EXT)) {
      for ($i=1; $i < 60; $i++) {
        if ( ! file_exists($filename . '-' . $i . '.' . $EXT)) {
          $filename = $filename . '-' . $i;
          break;
        }
      }
    }

    $yaml = Yaml::dump(array_map('trim', $data)) . '---';


    File::put($filename . '.' . $EXT, $yaml);
  }

  /**
   * Send a notification/response email
   *
   * @return void
   **/
  private function send($submission, $config)
  {
    if (array_get($this->config, 'master_killswitch')) return;

    $email = array_get($config, 'email', false);
    if ($email) {
      $attributes = array_intersect_key($email, array_flip(Email::$allowed));

      if (array_get($email, 'automagic') || array_get($email, 'automatic')) {
        $automagic_email = $this->buildAutomagicEmail($submission);
        $attributes['html'] = $automagic_email['html'];
        $attributes['text'] = $automagic_email['text'];
      }

      if ($html_template = array_get($email, 'html_template', false)) {
        $attributes['html'] = Parse::template(Theme::getTemplate($html_template), $submission);
      }

      if ($text_template = array_get($email, 'text_template', false)) {
        $attributes['text'] = Parse::template(Theme::getTemplate($text_template), $submission);
      }

      $attributes['email_handler']     = array_get($config, 'email_handler', false);
      $attributes['email_handler_key'] = array_get($config, 'email_handler_key', false);

      Email::send($attributes);
    }
  }

  /**
   * Assemble a simple key:value email
   *
   * @return void
   * @author
   **/
  private function buildAutomagicEmail($submission)
  {
    $the_magic = array('html' => '', 'text' => '');

    foreach($submission as $key => $value) {
      $the_magic['html'] .= "<strong>" . $key . "</strong>: " . $value . "<br><br>";
      $the_magic['text'] .= $key . ": " . $value . "\n";
    }

    return $the_magic;
  }

}
