<?php
 /*===============================================================================
	pWebFramework - lightweight templating engine
	----------------------------------------------------------------------------
	This class allows for very quick templating, using PHP directly as the
	templating engine.

	You should also take a look at 
	http://php.net/manual/en/control-structures.alternative-syntax.php

	Caution: this class makes use of output buffering.
	----------------------------------------------------------------------------
	@author		Sam Pospischil <pospi@spadgos.com>
	@date		2011-04-05
  ===============================================================================*/

class QuickTemplate
{
	private $templateFile;
	private $templateString;
	private $finalString;

	private $dirty = true;		// determines whether template output should be regenerated upon access

	private $vars = array();	// this is our map of variables to replace in the template

	public function __construct($templateFile = null)
	{
		if (isset($templateFile)) {
			$this->setInputFile($templateFile);
		}
	}

	public function setInputFile($path)
	{
		$this->templateFile = $path;
		$this->templateString = null;
		$this->dirty = true;
	}

	public function setInputText($string)
	{
		$this->templateString = $string;
		$this->templateFile = null;
		$this->dirty = true;
	}

	public function setVars($vars)
	{
		$this->vars = $vars;
		$this->dirty = true;
	}

	public function clearVars()
	{
		$this->vars = array();
		$this->dirty = true;
	}

	public function set($varName, $value)
	{
		$this->vars[$varName] = $value;
		$this->dirty = true;
	}

	public function get($varName)
	{
		return isset($this->vars[$varName]) ? $this->vars[$varName] : null;
	}

	public function erase($varName)
	{
		unset($this->vars[$varName]);
		$this->dirty = true;
	}

	public function getOutput()
	{
		if ($this->dirty) {
			$this->replaceVars();
			$this->dirty = false;
		}

		return $this->finalString;
	}

	// This can be called to forcefully update the template's output when
	// its internal data is known to be modified but not via the object's own
	// methods
	public function regenerate()
	{
		$this->replaceVars();
	}

	//==========================================================================
	
	private function replaceVars()
	{
		extract($this->vars);          // extract our vars to local namespace

        ob_start();
        if (isset($this->templateFile)) {
        	require($this->templateFile);
	    } else {
        	eval('?>' . $this->templateString . '<?php ');
        }
        $this->finalString = ob_get_clean();

        return $this->finalString;
	}
}
?>
