<?php

use Symfony\Component\Finder\Finder as Finder;

class API_raven extends API
{
	public function getOverviewData($formset=false)
	{
		$files = $this->getFiles($formset);
		$formsets = $this->getFormsets();

		return compact('files', 'formsets');
	}


	public function getFormsetData($formset_name)
	{
		$files   = $this->getFiles($formset_name);
		$formset = $this->getFormset($formset_name);
		$fields  = Helper::prettifyZeroIndexes(array_get($formset, 'control_panel:fields', $this->getFieldNames($files)));
		$metrics = $this->buildMetrics(array_get($formset, 'control_panel:metrics'), $files);

		return compact('files', 'fields', 'formset', 'metrics');
	}


	public function getFiles($formset=false)
	{
		$finder = new Finder();
		$subfolder = ($formset) ? '/' . $formset : '';

		$matches = $finder
			->name("*.yaml")
			->files()
			->followLinks()
			->in(BASE_PATH . '/'. $this->config['submission_save_path'] . $subfolder);

		$files = array();
        foreach ($matches as $file) {
        	$file_data = Parse::yaml($file->getContents());
        	
        	$meta =  array(
				'path' => $file->getRealpath(),
                'filename' => $file->getFilename(),
			    'folder' => $file->getRelativePath(),
        		'extension' => $file->getExtension()
        	);

        	$data = array('meta' => $meta, 'fields' => $file_data);

        	if ($formset) {
        		$files[] = $data;
        	} else {
	        	$files[$meta['folder']][] = $data;
        	}
        }
		
		return $files;
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
		$finder = new Finder();

		$matches = $finder
			->name("*.yaml")
			->files()
			->followLinks()
			->in(BASE_PATH . '/_config*/formsets');

		$formsets = array();
		foreach ($matches as $file) {
			$formset = substr($file->getBasename(), 0, -5);
			$config = $this->config + Parse::yaml($file->getRealPath());
			if ( ! array_get($config, 'control_panel:exclude')) {
				$formsets[$formset] = $config;
			}
		}

		return $formsets;
	}


	private function getFormset($formset_name)
	{
		$formsets = $this->getFormsets();

		$formset = array_get($formsets, $formset_name);
		$formset['name'] = array_get($formset, 'name', $formset_name);

		return $formset;
	}

	private function buildMetrics($operations, $data)
	{
		if ( ! is_array($operations)) {
			return false;
		}

		$metrics = array();
		foreach ($operations as $config) {
			switch ($config['type']) {
				case "unique":
					$metrics[] = $this->metricUnique($config, $data);
					break;
				case "tally":
					$metrics[] = $this->metricTally($config, $data);
					break;
				case "count":
					$metrics[] = $this->metricCount($config, $data);
					break;
				case "average":
					$metrics[] = $this->metricAverage($config, $data);
					break;
				case "median":
					$metrics[] = $this->metricMedian($config, $data);
					break;
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
	    	$value = $item['fields'][$field];
	        if (array_get($metrics, $value)) {
	        	$metrics[$value] = $metrics[$value] +1;
	        } else {
	        	$metrics[$value] = 1;
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
}