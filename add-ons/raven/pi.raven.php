<?php

require 'lib/stampie.phar';
require 'lib/guzzle.phar';

class Plugin_raven extends Plugin {

  public $meta = array(
    'name'       => 'Raven',
    'version'    => '0.9',
    'author'     => 'Jack McDade',
    'author_url' => 'http://jackmcdade.com'
  );

  # let's hold onto things, just don't get bitter.
  protected $template_vars = array();

  protected $validate_filters = array(
    'boolean'    => FILTER_VALIDATE_BOOLEAN,
    'email'      => FILTER_VALIDATE_EMAIL,
    'number'     => FILTER_VALIDATE_FLOAT,
    'integer'    => FILTER_VALIDATE_INT,
    'ip_address' => FILTER_VALIDATE_IP,
    'regex'      => FILTER_VALIDATE_REGEXP,
    'url'        => FILTER_VALIDATE_URL
  );

  protected $sanitize_filters = array(
    'email'   => FILTER_SANITIZE_EMAIL,
    'string'  => FILTER_SANITIZE_STRING,
    'url'     => FILTER_SANITIZE_URL,
    'integer' => FILTER_SANITIZE_NUMBER_INT,
    'number'  => FILTER_SANITIZE_NUMBER_FLOAT
  );

  var $cookie_required = 'r_req';
  var $cookie_allowed  = 'r_all';
  var $cookie_config   = 'r_con';

  public function __construct() {
    $this->app = Slim::getInstance();
    $this->config = Spyc::YAMLLoad('_config/add-ons/raven.yaml');
  }

  public function index() {

    $parser = new Lex_Parser();
    $parser->scope_glue(':');
    $parser->cumulative_noparse(true);
  
    if ( ! $_POST) {

      ///////////////////////////////////////////////////////////////////////////////////////////////////
      // SET CONFIG AND COOKIES -- HAS NOT YET BEEN SENT
      ///////////////////////////////////////////////////////////////////////////////////////////////////    

      # required fields
      $required        = $this->fetch_param('required', false);
      $required_fields  = $required ? $this->explode_options($required, false) : $required;
      
      # explicitly allowed fields
      $allowed         = $this->fetch_param('allowed', false);
      $allowed_fields  = $allowed ? $this->explode_options($allowed, true) : $allowed;

      # mailer options
      $form_set = $this->fetch_param('form_set', 'default_mail_set'); # allow multiple settings
      $default_mailer_config = $this->config[$form_set];
      $mailer_config_available_options = array('to','cc','bcc','from','from_field', 'subject','text_template','html_template','auto_text');
      
      $mailer_config_options = array();
      foreach ($mailer_config_available_options as $option) {
        if ($set = $this->fetch_param($option, false)) {
          $mailer_config_options[$option] = $set;
        }
      }

      # override defaults
      $mailer_config = array_merge($default_mailer_config, $mailer_config_options);

      $this->set_cookie($this->cookie_required, $required_fields);
      $this->set_cookie($this->cookie_allowed, $allowed_fields);
      $this->set_cookie($this->cookie_config, $mailer_config);

    } else {

      ///////////////////////////////////////////////////////////////////////////////////////////////////
      // FORM SUBMISSION & PROCESSING
      ///////////////////////////////////////////////////////////////////////////////////////////////////    

      $required_fields = $this->get_cookie($this->cookie_required);
      $allowed_fields  = $this->get_cookie($this->cookie_allowed);
      $mailer_config   = $this->get_cookie($this->cookie_config);

      if (isset($required_fields[0]) && $required_fields[0] == false) {
        $required_fields = false;
      }

      # cookie is storing arrays, so we need to peek at the first array element
      if (isset($allowed_fields[0]) && $allowed_fields[0] == false) {
        $allowed_fields = false;
      }

      # Check for required fields, validate, and sanitize the data
      $field_data = $this->process_form_data($_POST, $required_fields, $allowed_fields);

      $adapter = new Stampie\Adapter\Guzzle(new Guzzle\Service\Client());
      $service = strtolower($this->config['mail_service']);

      # Load the correct mailer adapter
      if ($service == 'postmark') {
        $mailer = new Stampie\Mailer\Postmark($adapter, $this->config['api_key']); }

      elseif ($service == 'mandrill') {
        $mailer = new Stampie\Mailer\Mandrill($adapter, $this->config['api_key']); }

      elseif ($service == 'sendgrid') {
        $mailer = new Stampie\Mailer\SendGrid($adapter, $this->config['api_key']); }

      elseif ($service == 'mailgun') {
        $mailer = new Stampie\Mailer\MailGun($adapter, $this->config['api_key']); }
            
      # To email 
      $message = new Message($mailer_config['to']);

      # Set email template
      if ($mailer_config['html_template']) {
        $message->setHtml(Statamic_Helper::get_template($mailer_config['html_template'], $field_data));
      }
      
      # Set text template
      if ($mailer_config['text_template']) {
        $message->setText(Statamic_Helper::get_template($mailer_config['text_template'], $field_data));
      }

      # override from email with form data
      $from_field = $this->fetch_param('from_field', false);

      if ($from_field) {
        if (isset($from_field, $field_data)) {
          $message->setFrom($field_data[$from_field]);
        }
      } else {
        $message->setFrom($mailer_config['from']);
      }

      # override from name with form data (unsupported with Stampie)
      // $from_name_field = $this->fetch_param('from_name_field', false);
      // if ($from_name_field) {
      //   if (isset($from_name_field, $field_data)) {
      //     $mailer_config['from_name'] = $field_data[$from_name_field];
      //   }
      // }

      $message->setSubject($mailer_config['subject']);
      $message->setCc($mailer_config['cc']);
      $message->setBcc($mailer_config['bcc']);

      // Returns Boolean true on success or throws an HttpException for error
      if ( ! $this->config['killswitch_engaged']) {
        $this->template_vars['success'] = $mailer->send($message);
      }
      
    }

    ///////////////////////////////////////////////////////////////////////////////////////////////////
    // SETUP THE FORM
    ///////////////////////////////////////////////////////////////////////////////////////////////////    
    
    # Attributes, accepted as colon/piped options
    # e.g. attr="class:form|id:contact-form"
    $attributes_string = '';  
    $attr = $this->fetch_param('attr', false);

    if ($attr) {
      $attributes_string = '';
      $attributes_array = $this->explode_options($attr, true);
      foreach ($attributes_array as $key => $value) {
        $attributes_string .= " {$key}='{$value}'";
      }
    }

    $html = $parser->parse($this->content, $this->template_vars);

    $include_form = $this->fetch_param('include_form', true, false, true);
    if ($include_form) {
      $html = "<form method='post'{$attributes_string}>".$html. '</form>';
    }
    
    return $html;

  }

  private function process_form_data($post, $required = false, $allowed = false) {

    # remove everything not allowed
    $allowed_data = array();
    if ($allowed) {
      foreach ($post as $key => $value) {
        if (array_key_exists($key, $allowed) && array_key_exists($key, $post)) {
          $allowed_data[$key] = $value;
        }
      }
      // $allowed = array_intersect_key($allowed, $allowed_data); # purge unneeded keys
    } else {
      # really don't recommend this, but hey, it's your funeral.
      $allowed_data = $post;
    }

    if ($required) {
      $missing_vars = array();
      # check for required fields
      foreach ($required as $field) {
        if ( ! array_key_exists($field, $allowed_data)) {
          $missing_vars[] = array('name' => $field);
        }
      }
      $this->template_vars['missing'] = $missing_vars;
    }

    # @todo: send validation errors
    # @todo: required check

    # set up the cleaners
    $validators = $this->combine_arr($allowed_data, $this->validate_filters);
    $sanitizers = $this->combine_arr($allowed_data, $this->sanitize_filters);

    # validate data types
    $valid_data = array();
    foreach ($allowed_data as $key => $value) {
      $valid_data[$key] = array_key_exists($key, $validators) ? filter_var($value, $validators[$key]) : $value;
    }

    # sanitize data
    $clean_data = array();
    foreach ($valid_data as $key => $value) {
      $clean_data[$key] = array_key_exists($key, $sanitizers) ? filter_var($value, $sanitizers[$key]) : $value;
    }

    return $clean_data;
  }

  function combine_arr($a, $b) { 
    $new = array();
    foreach ($a as $key => $val) {
      if (array_key_exists($val, $b)) {
        $new[$key] = $b[$val];
      }
    }
    return $new;
  } 

  private function set_cookie($cookie, $data, $expire = '1 day') {
    $this->app->setEncryptedCookie($cookie, json_encode($data), $expire);
  }

  private function get_cookie($cookie) {
    $data = $this->app->getEncryptedCookie($cookie);
    if ($data) {
      return (array)json_decode($data);
    }
    return false;
  }

}

class Message extends \Stampie\Message {

  var $headers = array();
  var $cc = null;
  var $bcc = null;

  /**
   * @param string $html
   */
  public function setHtml($html)
  {
      $this->html = $html;
  }

  /**
   * @param string $text
   * @throws \InvalidArgument
   */
  public function setText($text)
  {
      if ($text !== strip_tags($text)) {
          throw new \InvalidArgumentException('HTML Detected');
      }

      $this->text = $text;
  }


  /**
   * @return string
   */
  public function getHtml()
  {
      return $this->html;
  }

  /**
   * @return string
   */
  public function getText()
  {
      return $this->text;
  }

  /**
   * @param string $html
   */
  public function setFrom($from)
  {
      $this->from = $from;
  }

  /**
   * @return string
   */
  public function getFrom()
  {
      return $this->from;
  }

  /**
   * @return string
   */
  function setSubject($subject = null)
  {
    $this->subject = $subject;
  }

  /**
   * @return string
   */
  function getSubject()
  {
    return $this->subject;
  }

  /**
   * @return true
   */
  public function setHeaders($headers = array())
  {
    $this->headers = $headers;
  }

  /**
   * @return true
   */
  public function getHeaders()
  {
      return $this->headers;
  }

  /**
   * @return string
   */
  public function getReplyTo()
  {
      return $this->getFrom();
  }

  /**
  * @return null
  */
  public function setCc($cc = null)
  {
      $this->cc = $cc;
  }

  /**
   * @return null
   */
  public function getCc()
  {
      return $this->cc;
  }

  /**
  * @return null
  */
  public function setBcc($bcc = null)
  {
      $this->bcc = $bcc;
  }


  /**
   * @return null
   */
  public function getBcc()
  {
      return $this->bcc;
  }
}
