<?php

$classLoader = new SplClassLoader('Respect\Validation', __dir__.'/vendor/');
$classLoader->register();

use Respect\Validation\Validator as v;

class Hooks_raven extends Hooks {

  public function __construct() {
    $this->config = Yaml::Parse('_config/add-ons/raven/raven.yaml');
  }

  /**
   * Process a form submission
   *
   * @return void
   **/
  public function process() {
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
    $config  = array_merge($this->config, $formset);

   /*
    |--------------------------------------------------------------------------
    | Prep filters
    |--------------------------------------------------------------------------
    |
    | We jump through some PHP hoops here to filter, sanitize and validate
    | our form inputs.
    |
    */ 
   
    $allowed_fields   = array_flip($formset['allowed']);
    $required_fields  = array_flip($formset['required']);
    $validation_rules = isset($formset['validate']) ? $formset['validate'] : array();
    $messages         = isset($formset['messages']) ? $formset['messages'] : array();
    $return           = isset($hidden['return']) ? $hidden['return'] : Config::site_root();

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
    | Finalize & determine action
    |--------------------------------------------------------------------------
    |
    | Send back the errors if validation or require fields are missing.
    | If successful, save to file (if enabled) and send notification
    | emails (if enabled).
    |
    */

    if ($success) {
      Session::set_flash('raven', array('success' => true));

      # Shall we save?
      if (array_get($config, 'submission_save_to_file', false) === true) {
        $this->save($submission, $config['submission_save_path'], array_get($config, 'prefix', ''));
      }

      # Shall we send?
      if (array_get($config, 'send_notification_email', false) === true) {
        $this->send($submission, $config);
      }
      
      # Shall we...dance?
      URL::redirect(URL::format($return));

    } else {      
      $errors['success'] = false;

      Session::set_flash('raven', $errors);
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
        if ( ! $this->handle_validation_rule($fields[$key], $rules[$key])) {
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
  private function handle_validation_rule($field, $rule) {
    
    if ( ! is_array($rule)) {
        return v::$rule()->validate($field);
    } else {

      $spawn = new v;
      foreach ($rule as $rule => $params) {
        # make sure params are an array
        $params = ! is_array($params) ? (array) $params : $params;
        $spawn->addRule(v::buildRule($rule, $params));
      }
      return $spawn->validate($field);
    }
  }

  /**
   * Save submission to file
   *
   * @return void
   **/
  private function save($data, $location, $prefix = '') {

    if ( ! File::exists($location)) {
      File::mkdir($location);
    }
    
    $filename = $location . $prefix . date('Y-m-d-Gi', time());

    # Add an incrementer in the event two forms are submitted in the same minute
    if (File::exists($filename.'.yaml')) {
      for ($i=1; $i < 360; $i++) {
        if ( ! file_exists($filename.'-'.$i.'.yaml')) {
          $filename = $filename.'-'.$i;
          break;
        }
      }
    }

    $yaml = Yaml::dump(array_map('trim', $data));
    
    File::put($filename . '.yaml', $yaml);
  }

  /**
   * Send a notification/response email
   *
   * @return void
   **/
  private function send($submission, $config)
  {
    $email = array_get($config, 'email', false);
    if ($email) {
      $attributes = array_intersect_key($email, array_flip(Email::$allowed));
      
      if ($html_template = array_get($email, 'html_template', false)) {
        $attributes['html'] = Parse::template(Theme::get_template($html_template), $submission);
      }

      if ($text_template = array_get($email, 'text_template', false)) {
        $attributes['text'] = Parse::template(Theme::get_template($text_template), $submission);
      }

      $attributes['mail_handler']     = $config['mail_handler'];
      $attributes['mail_handler_key'] = $config['mail_handler_key'];

      Email::send($attributes);
    }
  }
}
