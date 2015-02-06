<?php

use Respect\Validation\Validator as v;

class Hooks_raven extends Hooks {

	public function request__post()
	{

		if (array_get($_POST, 'hidden:raven')) {

			$result = $this->process();
			$app = \Slim\Slim::getInstance();

			$app->config = array_merge($app->config, $result);
		}
	}

	public function control_panel__add_routes()
	{
		$app = \Slim\Slim::getInstance();
		$tasks = $this->tasks;

		$app->get('/raven', function() use ($app, $tasks) {
			authenticateForRole('admin');
			doStatamicVersionCheck($app);

			$template_list = array("raven-overview");
			Statamic_View::set_templates(array_reverse($template_list), __DIR__ . '/templates');

			$data = $tasks->getOverviewData();

			if (count($data['formsets']) === 1) {
				$app->redirect($app->urlFor('raven') . '/' . key($data['formsets']));
			}

			$app->render(null, array('route' => 'raven', 'app' => $app) + $data);

		})->name('raven');

		$app->get('/raven/:formset', function($formset) use ($app, $tasks) {
			authenticateForRole('admin');
			doStatamicVersionCheck($app);

			$template_list = array("raven-detail");
			Statamic_View::set_templates(array_reverse($template_list), __DIR__ . '/templates');

			$app->render(null, array('route' => 'raven', 'app' => $app) + $tasks->getFormsetData($formset));

		});

		$app->get('/raven/:formset/spam', function($formset) use ($app, $tasks) {
			authenticateForRole('admin');
			doStatamicVersionCheck($app);

			$template_list = array("raven-spam");
			Statamic_View::set_templates(array_reverse($template_list), __DIR__ . '/templates');

			$app->render(null, array('route' => 'raven', 'app' => $app) + $tasks->getFormsetSpamData($formset));

		});

		$app->get('/raven/:formset/export', function($formset) use ($app, $tasks) {
			authenticateForRole('admin');
			doStatamicVersionCheck($app);

			$res = $app->response();
			$res['Content-Type'] = 'text/csv';
			$res['Content-Disposition'] = 'attachment;filename=' . $formset . '-export.csv';

			$tasks->exportCSV($formset);
		});

		$app->post('/raven/:formset/batch', function($formset) use ($app, $tasks) {
			authenticateForRole('admin');
			doStatamicVersionCheck($app);
		
			$files = (array) Request::fetch('files');
			$action = Request::fetch('action');

			$count = count($files);

			foreach ($files as $file) {
				switch ($action) {
					case "delete":
						File::delete($file);
						break;
					case "spam":
						$tasks->markAsSpam($file);
						break;
					case "ham":
						$tasks->markAsHam($file);
						break;
				}
			}

			$app->flash('success', Localization::fetch('batch_' . $action));

			$app->redirect($app->urlFor('raven') . '/' . $formset);

		});

		$app->map('/raven/:formset/delete', function($formset) use ($app) {
			authenticateForRole('admin');
			doStatamicVersionCheck($app);
		
			$files = (array) Request::fetch('files');
			$count = count($files);
		
			foreach ($files as $file) {
				File::delete($file);
			}

			if ($count > 1) {
				$app->flash('success', Localization::fetch('files_deleted'));
			} else {
				$app->flash('success', Localization::fetch('file_deleted'));
			}

			$app->redirect($app->urlFor('raven') . '/' . $formset);

		})->via('GET', 'POST');
	}

	/**
	* Process a form submission
	*
	* @return void
	*/
	private function process()
	{

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
		| Add Files to POST
		|--------------------------------------------------------------------------
		|
		| Files are people too. Well, no, but they should at least be considered
		| fields, right? Treating files as fields will make our job easier.
		|
		*/

		foreach ($_FILES as $name => $file) {
			if ($file['success']) {
				// Set their submission value to true for now.
				// This will get updated when/if the file is actually uploaded.
				$submission[$name] = true;
			}
		}

		/*
		|--------------------------------------------------------------------------
		| Grab formset and collapse settings
		|--------------------------------------------------------------------------
		|
		| Formset settings are merged on top of the default raven.yaml config file
		| to allow per-form overrides.
		|
		*/
		$formset_name = array_get($hidden, 'formset');
		$formset = $formset_name . '.yaml';

		if (File::exists('_config/add-ons/raven/formsets/' . $formset)) {
			$formset = Yaml::parse('_config/add-ons/raven/formsets/' . $formset);
		} elseif (File::exists('_config/formsets/' . $formset)) {
			$formset = Yaml::parse('_config/formsets/' . $formset);
		} else {
			$formset = array();
		}

		if ( ! is_array($this->config)) {
			$this->log->warn('Could not find the config file.');
			$this->config = array();
		}

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
		$referrer         = Request::getReferrer();
		$return           = array_get($hidden, 'return', $referrer);
		$error_return     = array_get($hidden, 'error_return', $referrer);

		/*
		|--------------------------------------------------------------------------
		| Honeypot
		|--------------------------------------------------------------------------
		|
		| Spam sucks. Let's catch them. If the honeypot field is in the submission
		| we'll just stop right here.
		|
		*/

		$honeypot_field = array_get($config, 'honeypot', 'honeypot');

		if (array_get($submission, $honeypot_field)) {
			URL::redirect(URL::format($return));
		}

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
		| Requiring fields isn't required (ironic-five!), but if any are specified
		| and missing from the POST, we'll be squashing this submission right here
		| and sending back an array of missing fields.
		|
		*/

		if (count($required_fields) > 0) {
			$missing = array_flip(array_diff_key($required_fields, array_filter($submission)));

			if (count($missing) > 0) {
				foreach ($missing as $key => $field) {
					$errors['missing'][] = array('field' => $field);
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

		// Prepare a data array of fields and error messages use for template display
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
		| Upload Files
		|--------------------------------------------------------------------------
		|
		| Upload any files to their specified destination.
		|
		*/

		if (count($_FILES) > 0) {
			$files = array_intersect_key($_FILES, $allowed_fields);

			$upload_destination = array_get($config, 'upload_destination');

			foreach ($files as $name => $file) {
				if ($file['success']) {
					$submission[$name] = File::upload($file, $upload_destination);
				}
			}
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

		$success = Hook::run('raven', 'pre_process', 'replace', $success, compact('submission', 'config', 'success'));

		/*
		|--------------------------------------------------------------------------
		| Form Identifier
		|--------------------------------------------------------------------------
		|
		| In the event of multiple forms on a page, we'll be able to determine
		| which one was the one that had been triggered.
		|
		*/
		
		$this->flash->set('form_id', $hidden['raven']);

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

			if ($entry = array_get($hidden, 'edit')) {
				$this->completeEdit($submission, $config, $entry);
			} else {
				$this->completeNew($submission, $config, $formset_name);
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
			$this->flash->set('success', true);
			URL::redirect(URL::format($return));

		} else {
			$this->flash->set('success', false);
			$this->flash->set('errors', $errors);
			$this->flash->set('old_values', $_POST);
			URL::redirect(URL::format($error_return));
		}
	}

	private function completeEdit($submission, $config, $entry)
	{
		$content_set = ContentService::getContentByURL(Helper::decrypt($entry))->extract();

		// Bail if the content doesn't exist. Someone tweaked it. Tsk tsk.
		if ( ! count($content_set)) {
			return;
		}

		// Get front-matter from existing submission
		$content = current($content_set);
		$yaml = YAML::parseFile($content['_file']);

		// MERGE!@#!
		$submission = array_merge($yaml, $submission);

		// Update the entry
		$file_content = File::buildContent($submission, '');
		File::put($content['_file'], $file_content);
		
		// Shall we send?
		if (array_get($config, 'send_notification_email', false) === true) {
			$this->sendEmails($submission, $config, 'update');
		}

		// Save data to flash for use in raven:submission
		$this->flash->set('submission', $submission);
	}

	private function completeNew($submission, $config, $formset_name)
	{
		// Akismet?
		$is_spam = false;

		$akismet = array_get($config, 'akismet');
		if ($akismet && array_get($config, 'akismet_api_key')) {
			$is_spam = $this->tasks->akismetCheck(array(
				'permalink'            => URL::makeFull(URL::getCurrent()),
				'comment_type'         => $formset_name,
				'comment_author'       => array_get($submission, array_get($akismet, 'author')),
				'comment_author_email' => array_get($submission, array_get($akismet, 'email')),
				'comment_author_url'   => array_get($submission, array_get($akismet, 'url')),
				'comment_content'      => array_get($submission, array_get($akismet, 'content'))
			));
		}

		// Shall we save?
		if (array_get($config, 'submission_save_to_file', false) === true) {
			$file_prefix = Parse::template(array_get($config, 'file_prefix', ''), $submission);
			$file_suffix = Parse::template(array_get($config, 'file_suffix', ''), $submission);

			$file_prefix = ($is_spam) ? '_' . $file_prefix : $file_prefix;

			$this->save($submission, $config, $config['submission_save_path'], $is_spam);
		}

		// Shall we send?
		if ( ! $is_spam && array_get($config, 'send_notification_email', false) === true) {
			$this->sendEmails($submission, $config);
		}

		// Save data to flash for use in raven:submission
		$this->flash->set('submission', $submission);
	}

	/**
	* Loop through fields and filter them through individual validation rules
	*
	* @param array  $fields  Array of fields
	* @param array  $rules  Array of rules to validate with
	* @return array
	*/
	private function validate($fields, $rules)
	{
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
	* @param string  $field  Field to validate
	* @param mixed  $rule  Rule (or rules) to chain and validate with
	* @return bool
	*/
	private function handleValidationRule($field, $rule)
	{
		if ($field == '') return true; # only validate non-empty fields.

		$spawn = new v;
		if ( ! is_array($rule)) {
			$spawn->addRule(v::buildRule($rule));
		} else {
			foreach ($rule as $rule_key => $params) {
				$params = ! is_array($params) ? (array) $params : $params; # make sure params are an array
				$spawn->addRule(v::buildRule($rule_key, $params));
			}
		}

		return $spawn->validate($field);
	}

	/**
	* Save submission to file
	*
	* @param array  $data  Array of values to store
	* @param array  $config  Array of configuration values
	* @param string  $location  Path to folder where submissions should be saved
	* @param string  $prefix  Filename prefix to use for submission file
	* @param string  $suffix  Filename suffix to use for submission file
	* @return void
	*/
	private function save($data, $config, $location, $is_spam = false)
	{
		if (array_get($this->config, 'master_killswitch')) return;

		$EXT = array_get($config, 'submission_save_extension', 'yaml');

		// Clean up whitespace
		array_walk_recursive($data, function(&$value, $key) {
			$value = trim($value);
		});

		if ( ! File::exists($location)) {
			Folder::make($location);
		}

		if ($format = array_get($config, 'filename_format')) {

			$now = time();

			$time_variables = array(
				'year' => date('Y', $now),
				'month' => date('m', $now),
				'day' => date('d', $now),
				'hour' => date('H', $now),
				'minute' => date('i', $now),
				'minutes' => date('i', $now),
				'second' => date('s', $now),
				'seconds' => date('s', $now)
			);

			$available_variables = $time_variables + $data;

			$filename = Parse::template($format, $available_variables);

		} else {
			$filename = date('Y-m-d-Gi-s', time());
		}

		if ($is_spam) {
			$location = $location . 'spam/';
			Folder::make($location);
		}

		// Put it in the right folder
		$filename = $location . $filename;

		// Ensure a unique filename in the event two forms are submitted in the same second
		if (File::exists($filename . '.' . $EXT)) {
			for ($i=1; $i < 60; $i++) {
				if ( ! file_exists($filename . '-' . $i . '.' . $EXT)) {
					$filename = $filename . '-' . $i;
					break;
				}
			}
		}
		$filename .= '.' . $EXT;

		if ($EXT === 'json') {
			File::put($filename, json_encode($data));
		} else {
			File::put($filename, Yaml::dump($data) . '---');
		}

	}

	/**
	* Initiate sending of email(s)
	*
	* @param array  $submission  Array of submitted values
	* @param array  $config  Array of config values
	* @param string $event  Type of emails to send. ie. Create or Update
	* @return void
	*/

	private function sendEmails($submission, $config, $event = 'create')
	{
		if (array_get($this->config, 'master_killswitch')) return;

		// No need to continue if there is no email set
		if ( ! $email = array_get($config, 'email', false)) return;

		// If there's a single email config, turn it into an array anyway
		if ($email && isset($email['to'])) {
			$email = array($email);
		}

		// Only use the appropriate email event - ie. Create or Update
		$email = array_filter($email, function($val) use ($event) {
			return (array_get($val, 'on', 'create') == $event);
		});

		// Send emails
		foreach ($email as $e) {
			$this->send($submission, $e, $config);
		}
	}

	/**
	* Send a notification/response email
	*
	* @param array $submission Array of submitted values
	* @param array $email Array of email config values
	* @param array $config Array of config values
	*/
	private function send($submission, $email, $config)
	{

		$attributes = array_intersect_key($email, array_flip(Email::$allowed));

		if (array_get($email, 'automagic') || array_get($email, 'automatic')) {
			$automagic_email = $this->buildAutomagicEmail($submission);
			$attributes['html'] = $automagic_email['html'];
			$attributes['text'] = $automagic_email['text'];
		}

		if ($html_template = array_get($email, 'html_template', false)) {
			$attributes['html'] = Theme::getTemplate($html_template);
		}

		if ($text_template = array_get($email, 'text_template', false)) {
			$attributes['text'] = Theme::getTemplate($text_template);
		}

		/*
		|--------------------------------------------------------------------------
		| Parse all fields
		|--------------------------------------------------------------------------
		|
		| All email settings are parsed with the form data, allowing you, for
		| example, to send an email to a submitted email address.
		|
		|
		*/

		$globals = Config::getAll();
		
		array_walk_recursive($attributes, function(&$value, $key) use ($submission, $globals) {
			$value = Parse::contextualTemplate($value, $submission, $globals);
		});

		$attributes['email_handler']     = array_get($config, 'email_handler', null);
		$attributes['email_handler_key'] = array_get($config, 'email_handler_key', null);
		$attributes['smtp']              = array_get($config, 'smtp', null);

		Email::send($attributes);
	}

	/**
	* Assemble a simple key:value email
	*
	* @param array  $submission  Array of submitted values
	* @return array
	*/
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
