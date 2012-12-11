<?php

class Plugin_raven extends Plugin {

  public $meta = array(
    'name'       => 'Raven',
    'version'    => '0.9.9',
    'author'     => 'Jack McDade',
    'author_url' => 'http://jackmcdade.com'
  );

  /**
   * Raven form tag pair
   *
   * {{ raven:form }} {{ /raven:form }}
   *
   * @return html
   **/
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

    $formset = $this->fetch_param('formset', false);
    $return  = $this->fetch_param('return', URL::current());

    /*
    |--------------------------------------------------------------------------
    | Form HTML
    |--------------------------------------------------------------------------
    |
    | Raven writes a few hidden fields to the form to help processing data go
    | more smoothly. Form attributes are accepted as colon/piped options:
    | Example: attr="class:form|id:contact-form"
    |
    | The contents of the tagpair are inserted back into the template
    |
    */
    
    $attributes_string = '';

    if ($attr = $this->fetch_param('attr', false)) {
      $attributes_array = $this->explode_options($attr, true);
      foreach ($attributes_array as $key => $value) {
        $attributes_string .= " {$key}='{$value}'";
      }
    }

    $html  = "<form method='post' action='TRIGGER/raven/process' {$attributes_string}>\n";
    $html .= "<input type='hidden' name='hidden[formset]' value='{$formset}' />\n";
    $html .= "<input type='hidden' name='hidden[return]' value='{$return}' />\n";  
    $html .= $this->content;
    $html .= "</form>";
    
    return $html;

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
    return Session::get_flash('raven:success');
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
    if ($errors = Session::get_flash('raven')) {
      return Parse::template($this->content, $errors);
    }
    
    return false;
  }
}
