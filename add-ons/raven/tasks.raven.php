<?php

use Symfony\Component\Finder\Finder as Finder;

class Tasks_raven extends Tasks
{
	public function getOverviewData($formset=false)
	{
		$files    = $this->getAllFiles();
		$formsets = $this->getFormsets();

		return compact('files', 'formsets');
	}


	public function getFormsetData($formset_name)
	{
		$files    = $this->getFormsetFiles($formset_name);
		$spam     = $this->getFormsetSpam($formset_name);
		$formset  = $this->getFormset($formset_name);
		$formsets = $this->getFormsets();
		$fields   = Helper::prettifyZeroIndexes(array_get($formset, 'control_panel:fields', $this->getFieldNames($files)));
		$edit     = array_get($formset, 'control_panel:edit');
		$metrics  = $this->buildMetrics(array_get($formset, 'control_panel:metrics'), $files);

		return compact('files', 'fields', 'edit', 'formsets', 'formset', 'metrics', 'spam');
	}

	public function getFormsetSpamData($formset_name)
	{
		$spam    = $this->getFormsetSpam($formset_name);
		$formset  = $this->getFormset($formset_name);
		$formsets = $this->getFormsets();
		$fields   = Helper::prettifyZeroIndexes(array_get($formset, 'control_panel:fields', $this->getFieldNames($spam)));

		return compact('spam', 'fields', 'formsets', 'formset');
	}

	public function exportCSV($formset)
	{
		$files  = $this->getFormsetFiles($formset);
		$fields = Helper::prettifyZeroIndexes(array_get($formset, 'control_panel:fields', $this->getFieldNames($files)));

		$out = fopen('php://output', 'w');

		fputcsv($out, $fields);

		foreach ($files as $file) {
			fputcsv($out, $file['fields']);
		}

		fclose($out);
	}


	public function getAllFiles($formset=false)
	{
		$formsets = $this->getFormsets();
		
		$files = array();
		foreach ($formsets as $name => $config) {
			$files[$name] = $this->getFormsetFiles($name);
		}
		
		return $files;
	}

	public function getFormsetFiles($formset)
	{
		$config = $this->getFormset($formset);
		$extension = array_get($config, 'submission_save_extension', 'yaml');
		$path = Path::assemble(BASE_PATH, array_get($config, 'submission_save_path'));
		
		return $this->getFiles($formset, $path, $extension);
	}

	public function getFormsetSpam($formset)
	{
		$config = $this->getFormset($formset);
		$extension = array_get($config, 'submission_save_extension', 'yaml');
		$path = Path::assemble(BASE_PATH, array_get($config, 'submission_save_path'), 'spam');
		
		return $this->getFiles($formset, $path, $extension);
	}

	public function getFiles($formset, $path, $extension = "yaml")
	{
		if ( ! Folder::exists($path)) return array();
		
		$finder = new Finder();

		$matches = $finder
			->name("*." . $extension)
			->depth(0)
			->files()
			->followLinks()
			->in($path);

		$files = array();
		foreach ($matches as $file) {

			// Ignore page.md
			if ($file->getFilename() == 'page.md') continue;

			$file_data = Parse::yaml($file->getContents());
			$file_data['datestamp'] = date(array_get($this->config, 'datestamp_format', "m/d/Y"), $file->getMTime());
			
			$meta =  array(
				'path'      => $file->getRealpath(),
				'filename'  => $file->getFilename(),
				'formset'   => $formset,
				'extension' => $file->getExtension(),
				'datestamp' => $file->getMTime()
			);

			$meta['edit_path'] = Path::trimSlashes(Path::trimFileSystemFromContent(substr($meta['path'], 0, -1 - strlen($meta['extension']))));

			$data = array('meta' => $meta, 'fields' => $file_data);
			$files[] = $data;
		}
		
		return array_reverse($files);
	}


	private function getFieldNames($array, $folder=false)
	{
		$key = ($folder) ? $folder . ':fields' : 'fields';

		$result = array();
		foreach ($array as $sub) {
			$result = array_merge($result, array_get($sub, $key));
		}

		return array_keys($result);
	}


	private function getFormsets()
	{
		try {
			$finder = new Finder();

			$matches = $finder
				->name("*.yaml")
				->files()
				->followLinks()
				->in(BASE_PATH . '/_config*/formsets');

			$formsets = array();
			foreach ($matches as $file) {
				$formset = substr($file->getBasename(), 0, -5);
				$config = Parse::yaml($file->getRealPath()) + $this->config;
				if ( ! array_get($config, 'control_panel:exclude')) {
					$formsets[$formset] = $config;
				}
			}

			return $formsets;
		} catch(Exception $e) {
			return array();
		}
	}


	private function getFormset($formset_name)
	{
		$formsets = $this->getFormsets();

		$formset = array_get($formsets, $formset_name);
		$formset['name'] = array_get($formset, 'name', $formset_name);

		return $formset;
	}


	//
	// Metrics
	// 

	private function buildMetrics($operations, $data)
	{
		if ( ! is_array($operations) || count($data) <= 0) {
			return false;
		}

		$metrics = array();
		foreach ($operations as $config) {
			$method = Helper::camelCase('metric_' . $config['type']);
			
			if (is_callable(array($this, $method), false)) {
			    $metrics[] = $this->$method($config, $data);
			} else {
				throw new Exception("'".$config['type']."' is not a valid metric type.");
			}
		}

		return $metrics;
	}

	private function metricUnique($config, $data)
	{
		$field = $config['field'];

		$metrics = array();
		foreach ($data as $item) {
			if (array_get($item['fields'], $field)) {
				$metrics[] = $item['fields'][$field];
			}
		}

		$config['metrics'] = number_format(count(array_unique($metrics)), 0);

		return $config;
	}

	private function metricTally($config, $data)
	{
		$field = $config['field'];

		$metrics = array();
		foreach ($data as $item) {
			$values = Helper::ensureArray($item['fields'][$field]);

			foreach ($values as $value) {
				if (array_get($metrics, $value)) {
					$metrics[$value] = $metrics[$value] +1;
				} else {
					$metrics[$value] = 1;
				}
			}
		}

		if ($sort_by = array_get($config, 'sort_by')) {
			if ($sort_by === 'key') {
				ksort($metrics);
			} elseif ($sort_by === 'value') {
				arsort($metrics);
			}

			if (array_get($config, 'sort_dir') === 'desc') {
				$metrics = array_reverse($metrics, true);
			}
		}

		$config['metrics'] = $metrics;

		return $config;
	}

	private function metricCount($config, $data)
	{
		$field = $config['field'];

		$count = 0;
		foreach ($data as $item) {
			if (array_get($item['fields'], $field)) {
				$count++;
			}
		}

		$config['metrics'] = number_format($count, 0);

		return $config;
	}

	private function metricAverage($config, $data)
	{
		$field = $config['field'];

		$count = count($data);
		$total = 0;
		foreach ($data as $item) {
			$total = $total + array_get($item['fields'], $field);
		}

		$config['metrics'] = number_format(($total/$count), array_get($config, 'precision', 2));

		return $config;
	}

	private function metricMedian($config, $data)
	{
		$field = $config['field'];

		$count = count($data);

		$numbers = array();
		foreach ($data as $item) {
			$numbers[] = array_get($item['fields'], $field);
		}

		sort($numbers);
		$middle_value = floor(($count-1)/2);

		if ($count % 2) {
			// odd number
			$median = $numbers[$middle_value];
		} else {
			// even number
			$low = $number[$middle_value];
			$high = $numbers[$middle_value + 1];
			$median = (($low + $high)/2);
		}

		$config['metrics'] = number_format($median, array_get($config, 'precision', 0));

		return $config;
	}

	private function metricSum($config, $data)
	{
		$field = $config['field'];

		$config['metrics'] = 0;
		foreach ($data as $item) {
			$config['metrics'] = $config['metrics'] + array_get($item['fields'], $field);
		}

		return $config;
	}

	private function metricMax($config, $data)
	{
		$field = $config['field'];

		$numbers = array_map(function($item) use($field) {
		  return array_get($item['fields'], $field);
		}, $data);

		$config['metrics'] = max($numbers);

		return $config;
	}

	private function metricMin($config, $data)
	{
		$field = $config['field'];

		$numbers = array_map(function($item) use($field) {
		  return array_get($item['fields'], $field);
		}, $data);

		$config['metrics'] = min($numbers);

		return $config;
	}

	/**
	* Run an Akismet check for spam
	* @param array $comment Message data. Required keys:
	*      permalink - the permanent location of the entry the comment was submitted to
	*      comment_type - may be blank, comment, trackback, pingback, or a made up value like "registration"
	*      comment_author - name submitted with the comment
	*      comment_author_email - email address submitted with the comment
	*      comment_author_url - URL submitted with comment
	*      comment_content - the content that was submitted
	* @return bool   true if spam
	*/
	public function akismetCheck($comment)
	{
		$loader = new SplClassLoader('Rzeka', __DIR__ . '/vendor/');
	    $loader->register();

		$connector = new Rzeka\Service\Akismet\Connector\Curl();
		$akismet   = new Rzeka\Service\Akismet($connector);

		$api_key  = $this->config['akismet_api_key'];
		$site_url = Config::get('site_url');

		if ( ! $akismet->keyCheck($api_key, $site_url)) {
			Log::error('Invalid Akismet API key', 'raven');
			return false;
		};

		return $akismet->check($comment);
	}

	public function markAsSpam($file)
	{
		$info = new SplFileInfo($file);
		$filename = $info->getFilename();

		$directory = str_replace($filename, '', $file) . 'spam/';
		Folder::make($directory);

		File::move($file, Path::assemble($directory, $filename));
	}

	public function markAsHam($file)
	{
		File::move($file, str_replace('spam/', '', $file));
	}
}