<?php

class Plugin_raven extends Plugin {

	public $meta = array(
		'name'       => 'Raven',
		'version'    => '2.0',
		'author'     => 'Statamic',
		'author_url' => 'http://statamic.com'
	);

  /**
   * Raven form tag pair
   *
   * {{ raven:form }} {{ /raven:form }}
   *
   * @return string
   */
	public function form()
	{

		/*
		|--------------------------------------------------------------------------
		| Formset
		|--------------------------------------------------------------------------
		|
		| Raven really needs a formset to make it useful and secure. We may even
		| write a form decorator in the future to generate forms from formsets.
		|
		*/

		$formset      = $this->fetchParam('formset', false);
		$return       = $this->fetchParam('return', URL::getCurrent());
		$error_return = $this->fetchParam('error_return', URL::getCurrent());
		$multipart    = ($this->fetchParam('files', false)) ? "enctype='multipart/form-data'" : '';

		// Sanitize data before returning it for display
		$old_values = array_map('htmlspecialchars', $this->flash->get('old_values', array()));

		// Set old values to re-populate the form
		$data = array();
		array_set($data, 'value', $old_values);
		array_set($data, 'old_values', $old_values);

		/*
		|--------------------------------------------------------------------------
		| Form HTML
		|--------------------------------------------------------------------------
		|
		| Raven writes a few hidden fields to the form to help processing data go
		| more smoothly. Form attributes are accepted as colon/piped options:
		| Example: attr="class:form|id:contact-form"
		|
		| Note: The content of the tag pair is inserted back into the template
		|
		*/

		$form_id = $this->fetchParam('id', true);

		$attributes_string = '';

		if ($attr = $this->fetchParam('attr', false)) {
			$attributes_array = Helper::explodeOptions($attr, true);
			foreach ($attributes_array as $key => $value) {
				$attributes_string .= " {$key}='{$value}'";
			}
		}

		$html  = "<form method='post' {$multipart} {$attributes_string}>\n";
		$html .= "<input type='hidden' name='hidden[raven]' value='true' />\n";
		$html .= "<input type='hidden' name='hidden[formset]' value='{$formset}' />\n";
		$html .= "<input type='hidden' name='hidden[return]' value='{$return}' />\n";
		$html .= "<input type='hidden' name='hidden[error_return]' value='{$error_return}' />\n";

		/*
		|--------------------------------------------------------------------------
		| Hook: Form Begin
		|--------------------------------------------------------------------------
		|
		| Occurs in the middle the form allowing additional fields to be added.
		| Has access to the current fieldset. Must return HTML.
		|
		*/

		$html .= Hook::run('raven', 'inside_form', 'cumulative', '');

		/*
		|--------------------------------------------------------------------------
		| Hook: Content Preparse
		|--------------------------------------------------------------------------
		|
		| Allows the modification of the tag data inside the form. Also has access
		| to the current formset.
		|
		*/

		$html .= Hook::run('raven', 'content_preparse', 'replace', $this->content, $this->content);

		$html .= "</form>";

		return Parse::Template($html, $data);

	}

	/**
	* Returns true or false based on form success
	*
	* Set in hooks.raven.php -> process()
	*
	* @return bool
	**/
	public function success()
	{
		if ( ! $this->isActiveForm()) {
			return false;
		}

		return $this->flash->get('success');
	}

	/**
	* Returns an array if errors are present, false if not
	*
	* Set in hooks.raven.php -> process()
	*
	* @return mixed
	**/
	public function errors()
	{
		if ( ! $this->isActiveForm()) {
			return false;
		}

		if ($errors = $this->flash->get('errors')) {
			return Parse::template($this->content, $errors);
		}

		return false;
	}

	public function has_errors()
	{
		if ( ! $this->isActiveForm()) {
			return false;
		}

		if ($errors = $this->flash->get('errors')) {
			if (is_array(array_get($errors, 'invalid')) || is_array(array_get($errors, 'missing'))) {
				return true;
			}
		}

		return false;
	}


	/**
	* Returns true or false based on whether the form was the one submitted
	*
	* If using multiple forms on one page, `raven` tags should have a
	* common `id` parameter between each set.
	*
	* @return bool
	**/
	private function isActiveForm()
	{
		return $this->flash->get('form_id') == $this->fetchParam('id', 1);
	}
}
