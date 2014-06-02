<?php

use Symfony\Component\Finder\Finder as Finder;

class API_raven extends API
{
	public function getControlPanelData()
	{
		$files = $this->getFiles();

		rd($files);
		return $this->getFiles();
	}

	public function getFiles()
	{
		$finder = new Finder();

		$finder->in(BASE_PATH . '/'.$this->config['submission_save_path']);
		$matches = $finder->files()->followLinks();

		$files = array();
        foreach ($matches as $file) {
        	$data = Parse::yaml($file->getContents());
        	
        	$meta =  array(
				'path' => $file->getRealpath(),
                'filename' => $file->getFilename(),
			    'folder' => $file->getRelativePath(),
        		'extension' => $file->getExtension()
        	);

        	$files[] = array('meta' => $meta, 'data' => $data);

        }

		$fields = array_keys(call_user_func_array('array_merge', $files));

		return compact('files', 'fields');
	}
}