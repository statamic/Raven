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

		return compact('files', 'fields', 'formset');
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
}