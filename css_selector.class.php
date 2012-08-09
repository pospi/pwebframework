<?php
/**
 * SelectorDOM.
 *
 * Persitent object for selecting elements.
 *
 * $dom = new SelectorDOM($html);
 * $links = $dom->select('a');
 * $list_links = $dom->select('ul li a');
 *
 * @package	pWebFramework
 * @author	TJ Holowaychuk <tj@vision-media.ca>
 * @author	Sam Pospischil <pospi@spadgos.com>
 * @license	MIT
 */
define('SELECTOR_VERSION', '1.1.5');

class SelectorDOM
{
	public $dom;	// DOMDocument instance being queried
	public $xpath;	// DOMXpath object responsible for querying

	/**
	 * @param [string]   $html         HTML string to perform CSS queries on
	 * @param [callable] $errorHandler Optional custom error handler for handling LibXML errors. If not present, parser errors are ignored.
	 *                                 You will probably want to interpret error codes within this callback and throw an exception to be caught elsewhere.
	 */
	public function SelectorDOM($html, $errorHandler = null)
	{
		if ($errorHandler) {
			$xmlErrorSetting = libxml_use_internal_errors(true);
		}

		$this->dom = new DOMDocument();

		if ($errorHandler) {
			$this->dom->loadHTML($html);
			$errors = libxml_get_errors();
			libxml_clear_errors();
			foreach ($errors as $error) {
				if (false === call_user_func($errorHandler, $error, $html)) {
					$this->dom = false;
					return;
				}
			}
		} else {
			@$this->dom->loadHTML($html);
		}

		$this->xpath = new DOMXpath($this->dom);

		if ($errorHandler) {
			libxml_use_internal_errors($xmlErrorSetting);
		}
	}

	public function select($selector, $as_array = true)
	{
		$elements = $this->xpath->evaluate(self::selector_to_xpath($selector));
		return $as_array ? self::elements_to_array($elements) : $elements;
	}

	//--------------------------------------------------------------------------

	/**
	 * Select elements from $html using the css $selector.
	 * When $as_array is true elements and their children will
	 * be converted to array's containing the following keys (defaults to true):
	 *
	 * - name : element name
	 * - text : element text
	 * - children : array of children elements
	 * - attributes : attributes array
	 *
	 * Otherwise regular DOMElement objects will be returned.
	 */
	public static function select_elements($selector, $html, $as_array = true) {
		$dom = new SelectorDOM($html);
		return $dom->select($selector, $as_array);
	}

	/**
	 * Convert $elements to an array.
	 */
	public static function elements_to_array($elements) {
		$array = array();
		for ($i = 0, $length = $elements->length; $i < $length; ++$i) {
			if ($elements->item($i)->nodeType == XML_ELEMENT_NODE) {
				array_push($array, self::element_to_array($elements->item($i)));
			}
		}
		return $array;
	}

	/**
	 * Convert $element to an array.
	 */
	public static function element_to_array($element) {
		$array = array(
			'name' => $element->nodeName,
			'attributes' => array(),
			'text' => $element->textContent,
			'children' => self::elements_to_array($element->childNodes)
			);
		if ($element->attributes->length) {
			foreach($element->attributes as $key => $attr) {
				$array['attributes'][$key] = $attr->value;
			}
		}
		return $array;
	}

	/**
	 * Convert $selector into an XPath string.
	 */
	public static function selector_to_xpath($selector) {
		$selector = 'descendant-or-self::' . $selector;
		// ,
		$selector = preg_replace('/\s*,\s*/', '|descendant-or-self::', $selector);
		// :button, :submit, etc
		$selector = preg_replace('/:(button|submit|file|checkbox|radio|image|reset|text|password)/', 'input[@type="\1"]', $selector);
		// [id]
		$selector = preg_replace('/\[(\w+)\]/', '*[@\1]', $selector);
		// foo[id=foo]
		$selector = preg_replace('/\[(\w+)=[\'"]?(.*?)[\'"]?\]/', '[@\1="\2"]', $selector);
		// [id=foo]
		$selector = str_replace(':[', ':*[', $selector);
		// div#foo
		$selector = preg_replace('/([\w\-]+)\#([\w\-]+)/', '\1[@id="\2"]', $selector);
		// #foo
		$selector = preg_replace('/\#([\w\-]+)/', '*[@id="\1"]', $selector);
		// div.foo
		$selector = preg_replace('/([\w\-]+)\.([\w\-]+)/', '\1[contains(@class,"\2")]', $selector);
		// .foo
		$selector = preg_replace('/\.([\w\-]+)/', '*[contains(@class,"\1")]', $selector);
		// div:first-child
		$selector = preg_replace('/([\w\-]+):first-child/', '*/\1[position()=1]', $selector);
		// div:last-child
		$selector = preg_replace('/([\w\-]+):last-child/', '*/\1[position()=last()]', $selector);
		// :first-child
		$selector = str_replace(':first-child', '*/*[position()=1]', $selector);
		// :last-child
		$selector = str_replace(':last-child', '*/*[position()=last()]', $selector);
		// div:nth-child
		$selector = preg_replace('/([\w\-]+):nth-child\((\d+)\)/', '*/\1[position()=\2]', $selector);
		// :nth-child
		$selector = preg_replace('/:nth-child\((\d+)\)/', '*/*[position()=\1]', $selector);
		// :contains(Foo)
		$selector = preg_replace('/([\w\-]+):contains\((.*?)\)/', '\1[contains(string(.),"\2")]', $selector);
		// >
		$selector = preg_replace('/\s*>\s*/', '/', $selector);
		// ~
		$selector = preg_replace('/\s*~\s*/', '/following-sibling::', $selector);
		// +
		$selector = preg_replace('/\s*\+\s*([\w\-]+)/', '/following-sibling::\1[position()=1]', $selector);
		// ' '
		$selector = preg_replace('/\s+/', '/descendant::', $selector);
		$selector = str_replace(']*', ']', $selector);
		$selector = str_replace(']/*', ']', $selector);
		return $selector;
	}
}
