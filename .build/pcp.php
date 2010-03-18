#!/usr/bin/php -q
<?
/**
 * @file pcp.php
 * @package PCP: CSS Preprocessor
 * @version 0.2
 * @copyright 2010 Josh Channings <josh+pcp@channings.me.uk>
 * @license LGPLv3
 */

global $pcp;
if(basename($argv[0]) == basename(__FILE__)) main();

/**
 * CLI entry point
 */
function main()
{
	global $pcp, $argc, $argv;

	// Get filenames from options
	$diff = $argv[array_search('-d', $argv) + 1];
	$cache = $argv[array_search('-c', $argv) + 1];
	$output = $argv[array_search('-o', $argv) + 1];

	// Help/usage message
	if(
		   in_array('-h', $argv)
		|| in_array('--help', $argv)
		|| $argc == 1
		|| !$output
	){
		echo <<<EOF
PCP: CSS Preprocessor [Copyright 2010 Josh Channings <josh+pcp@channings.me.uk>]

Usage: {$argv[0]} [options] file.pcp file.css ...
Options:
-d	Serialized cache input file (to generate a diff from)
-c	Serialized cache output file
-o	Static CSS output file

EOF;
		exit(0);
	}

	$pcp = new PCP($diff);

	// Add filenames in args to sources list
	foreach($argv as $n => $arg)
	{
		if(
			   $n == 0
			|| $arg == '-o'
			|| $arg == '-c'
			|| $arg == '-d'
		)
			$opt = true;
		else
		{
			if(!$opt)
				$pcp->add_source($arg);

			$opt = false;
		}
	}

	$pcp->parse();

	if($output) file_put_contents($output, $pcp->css(true));
	if($cache)  file_put_contents($cache, $pcp->cache());
}

/**
 * PCP Engine
 *
 * @example
 * $pcp = new PCP(get_file_contents('.pcp-cache'));
 * $pcp->add_source(array('src/layout.pcp', 'src/typography.pcp'));
 * $pcp->parse();
 * file_put_contents('min.css', $pcp->css());
 * file_put_contents('min.js', $pcp->js());
 * file_put_contents('.pcp-php-cache', $pcp->cache());
 */
class PCP
{
	var $state = array(
		  'sources' => array()
		, 'variables' => array()
		// $state['selectors']['div.foo#bar'] = array(PCP_Property, PCP_Property, ...)
		, 'selectors' => array()
	);

	private $pseudo_classes = array(
		// CSS 1
		  'active'
		, 'hover'
		, 'link'
		, 'visited'

		// CSS 2
		, 'first-child'
		, 'focus'
		, 'lang'

		// CSS 3
		, 'nth-child'
		, 'nth-last-child'
		, 'nth-of-type'
		, 'nth-last-of-type'
		, 'last-child'
		, 'first-of-type'
		, 'last-of-type'
		, 'only-child'
		, 'only-of-type'
		, 'root'
		, 'empty'
		, 'target'
		, 'enabled'
		, 'disabled'
		, 'checked'
		, 'not'
	);

	/**
	 * @param string $cache Filename of engine cache
	 */
	function __construct($cache = null)
	{
		if(file_exists($cache))
			$this->state = unserialize(@file_get_contents($cache));

		// Hook into global
		global $pcp;
		$pcp = &$this;
	}

	/// @param string|array $source .pcp/.css file to add to the list of files to parse
	function add_source($source)
	{
		if(is_array($source))
		{
			foreach($source as $src)
				if(file_exists($src))
					array_push($this->state['sources'], $src);
		} else
		{
			if(file_exists($source))
				array_push($this->state['sources'], $source);
		}
	}

	/// Clear state, parse sources into state
	function parse()
	{
		// Clear the state
		$this->state['variables'] = array();
		$this->state['selectors'] = array();

		foreach($this->state['sources'] as $src)
		{
			if(false !== ($fd = fopen($src, 'r')))
			{
				$ln = 0; $cn = 0;		// Line number, Column number
				$buf = '';				// Chomped input
				$selector = array();	// Stack of working selectors
				$p = null;				// Current property. @see PCP_Property

				while(false !== ($c = fgetc($fd)))
				{
					switch($c)
					{
						case '{':	// selector { properties

							// Convert buffer into selector, then clear
							$sels = explode(',', trim($buf));
							$buf = '';

							// Add selector to stack
							if(count($selector))
								array_push($selector, end($selector).'>'.$sels[0]);
							else
								array_push($selector, $sels[0]);

							// Instantiate PCP_Selector for new name
							if(!isset($this->state['selectors'][end($selector)]))
								$this->state['selectors'][end($selector)] = new PCP_Selector(end($selector));

							// Add any comma-delimited selectors as references
							for($i = 1;$i < count($sels);$i++)
								$this->state['selectors'][end($selector)]->add_ref($sels[$i]);
							break;

						case '}':	// properties }

							// Check for no open selectors
							if(count($selector) < 1)
							{
								trigger_error("$src:$ln:$cn: Unexpected '}'", E_USER_ERROR);
								fclose($fd);
							} else
							{
								// Check for open property
								if($p)
								{
									// Check for potential value in the buffer
									if(strlen(trim($buf)))
									{
										trigger_error(
											  "$src:$ln:$sc: Property not closed properly. "
											 ."Assumed '{$p->name}: ".trim($buf).";'"
											, E_USER_WARNING
										);

									} else
									{
										trigger_error(
											  "$src:$ln:$cn: Selector closed with open property"
											, E_USER_ERROR
										);
										fclose($fd);
									}
								}
								array_pop($selector);
							}

							break;

						case ':':	// property name : property value

							// Is there already an open property?
							if($p)
								$buf .= ':';
							else
							{
								// Look ahead for pseudo-class names
								// We need to do this to differentiate between nested selectors
								// and properties.
								// TODO Maybe redo this to just see which comes first of (;|{)?
								$la = fread($fd, 24);
								fseek($fd, -(strlen($la)), SEEK_CUR);
								$la = preg_replace('/\W.*/', '', $la);

								// If next word is a valid pseudo-class, pass ':' through to $buf
								if(in_array($la, $this->pseudo_classes))
								{
									$buf .= ':';
									break;
								}

								$p = new PCP_Property(
									  end($selector)
									, self::clean_token($buf)
								);

								$p->src = $src;
								$p->ln = $ln;
								$p->cn = $cn;

								$buf = '';
								$this->state['selectors'][end($selector)]->properties[$p->name] = &$p;
							}

							break;

						case ';':	// property value ; || reference name ;

							if($p)
							{
								$p->set(trim($buf));
								$buf = '';
								unset($p);
							} else
							{
								// Mixin references

								// Check there is a selector to refer from
								if(!count($selector))
								{
									trigger_error(
										  "$src:$ln:$cn: Unexpected ';'"
										, E_USER_ERROR
									);
									fclose($fd);
									break;
								}

								$ref = self::clean_token($buf);
								$buf = '';

								// Make sure the referenced selector exists
								if(!isset($this->state['selectors'][$ref]))
									$this->state['selectors'][$ref] = new PCP_Selector($ref);

								// Add a reference to the current selector
								$this->state['selectors'][$ref]->add_ref(end($selector));
							}
							break;

						case "\n":	// Newline
							$buf .= ' ';
							$ln++;
							$cn = 0;
							break;

						default:	// chomp
							$buf .= $c;
							$cn++;
							break;
					}

				} // while(fgetc())

				fclose($fd);

			} // fopen()
		} // foreach($state['sources'])
	}

	/**
	 * @param string $base Selector to extend
	 * @param string $sub Selector to put changes into
	 * @param array $delta name=>value pairs of properties to modify in the subclass
	 * @returns bool True on success, false on failure
	 */
	function extend($base, $sub, $delta)
	{
		// Get specified selector from $state, or bomb if it's not there
		if(false === ($properties = $this->state['selectors'][$base]->properties))
			return false;

		// Make sure sub selector exists
		if(!isset($this->state['selectors'][$sub]))
			$this->state['selectors'][$sub] = new PCP_Selector($sub);

		// Clone PCP_Properties from old selector
		foreach($this->state['selectors'][$base]->properties as $old_p)
		{
			$p = clone $old_p;

			$p->selector = str_replace($base, $sub, $p->selector);
			$p->changed = false;

			$p->deps = null;
			$deps = $p->dependants;
			$p->dependants = array();


			// Clone dependant tree
			foreach($deps as $old_dep)
			{
				$dep = clone $old_dep;

				$dep->selector = str_replace($base, $sub, $dep->selector);

				$dep->deps = null;
				$dep->changed = false;

				$this->state['selectors'][$dep->selector]->properties[$dep->name] = $dep;
				$p->dependants["{$dep->selector}->{$dep->name}"] = $dep;

			}

			$this->state['selectors'][$sub]->properties[$p->name] = $p;
		}

		foreach($delta as $name => $value)
		{
			if(isset($this->state['selectors'][$sub]->properties[$name]))
				$this->state['selectors'][$sub]->properties[$name]->set($value);
			else
				$this->state['selectors'][$sub]->properties[$name] =
					new PCP_Property($sub, $name, $value);
		}
	}
	/// Return a string representing the state of the engine
	function cache()
	{
		if($this->state)
			return serialize($this->state);
	}

	/**
	 * Generate a string of static CSS
	 * @param bool $diff Should the output be a diff from the last call, or the entire state?
	 * @returns string Minified CSS
	 */
	function css($diff = true)
	{
		ob_start();

		foreach($this->state['selectors'] as $sel)
		{
			ob_start();

			// Don't output selectors with no changed properties
			$empty = true;

			echo "{$sel->output_name()}{";

			foreach($sel->properties as $p)
				if($p->name[0] != '$' && (!$diff || $p->changed()))
				{
					echo "{$p->name}:{$p->value(true)};";
					$empty = false;
				}

			echo '}';

			if(!$empty)
				echo ob_get_clean();
			else
				ob_clean();
		}

		return ob_get_clean();
	}

	/// Write javascript engine
	function js()
	{
	}
	/// Validate selectors/property names and prepare for use as a hash
	function clean_token($sel)
	{
		return preg_replace(
			array(
				  '/\s+>\s+/'			// Strip whitespace from around '>'
				, '/\s+\+\s+/'			// Strip whitespace from around '+'
				, '/\s\s+/'				// Strip extraneous whitespace
				, '/^\s+/'				// Strip whitespace from beginning
				, '/\s+$/'				// Strip whitespace from end
				, '/\/\*.*?\*\//'		// /* Comment */
				, '/\/\/.*?$/'			// // Comment
			), array(
				  '>'
				, '+'
				, ' '
			), $sel
		);
	}
}

class PCP_Selector
{
	private $primary;
	private $secs = array();

	public $properties = array();

	public function __construct($name)
	{
		$this->primary = PCP::clean_token($name);
	}

	/** Get the primary name of this selector */
	public function name() {return $this->primary;}

	/** Get comma-delimited primary name and references for CSS output */
	public function output_name()
	{
		if(count($this->secs))
			return "{$this->primary},".implode(',', $this->secs);
		else
			return $this->primary;
	}
	/**
	 * Add a reference to this selector from $sel
	 */
	public function add_ref($sel) {array_push($this->secs, PCP::clean_token($sel));}

}
class PCP_Property
{
	var $name;				/// string
	var $value;				/// string Unprocessed value
	var $rvalue;			/// string Real value
	var $src;				/// string Source file
	var $ln;				/// int Line number
	var $cn;				/// int Column number
	var $selector;			/// string Selector containing this property
	var $deps;				/// array Properties this depends on. @see PCP_Property
	var $dependants;		/// array Properties that depend on this. @see PCP_Property
	var $changed;			/// bool Has set() been called since last value()

	function __construct($selector, $name, $value = null)
	{
		// TODO Validate these inputs, add error messages
		$this->deps = array();
		$this->dependants = array();
		$this->name = $name;
		$this->selector = $selector;

		if($value !== null)
			$this->set($value);
	}

	/**
	 * Compute new value
	 * @param bool $is_output Decides whether the property will be marked as unchanged
	 */
	function value($is_output = false)
	{
		// Return precomputed value if no inputs have changed
		if(!$this->changed())
			return $this->rvalue;

		// Initialize rvalue
		$this->rvalue = $this->value;

		$deps = $this->deps();

		// Loop through deps, replace tokens with values
		foreach($deps as $dep => $p)
			if($p->value())
				$this->rvalue = str_replace($dep, $p->value($is_output), $this->rvalue);

		// Reduce maths ops
		$this->rvalue = PCP_Property::compute($this->rvalue);

		// Reset changed indicator and return real value
		if($is_output)
			$this->changed = false;
		return $this->rvalue;
	}

/**
 * Register a dependant on this property.
 * We need to be able to navigate down the dependancy tree in order to generate diffs.
 * @param PCP_Property $p Property that wants to depend on this one
 * @returns bool True on success, false on failure
 */
private function add_dependant(&$p)
{
	if($p && !isset($this->dependants["{$p->selector}->{$p->name}"]))
		$this->dependants["{$p->selector}->{$p->name}"] = $p;
	else
		return false;
	return true;
}
/**
 * Deregister a dependant.
 * Used when the previously dependant value is changed to no longer include this one.
 * @param PCP_Property $p Property to remove from dependant list
 * @returns bool True when property is removed, false when property wasn't there
 */
private function remove_dependant(&$p)
{
	if(isset($this->dependants["{$p->selector}->{$p->name}"]))
	{
		$this->dependants["{$p->selector}->{$p->name}"] = null;
		return true;
	} else
		return false;
}
	/**
	 * Set new value
	 */
	function set($new)
	{
		$this->value = $new;

		// Tell changed() about the new value
		$this->changed = true;

		// Invalidate dependencies
		$this->deps = null;
	}

	/**
	 * Has $this->value, or any dependency values changed?
	 * This function will be called frequently - it must be fast
	 * @param null|bool new_value Set $changed to a specified value
	 * @returns bool
	 */
	function changed($new_value = null)
	{
		if($new_value !== null)
			$this->changed = $new_value;

		// Check for changed $this->value
		if($this->changed == true)
			return true;

		// Check for changed dependencies
		foreach($this->deps() as $dep)
			if($dep->changed())
				return true;

		// Nothing changed
		return false;
	}

	/**
	 * @returns array Array of @see PCP_Property dependencies
	 */
	private function deps()
	{
		global $pcp;

		// Return cached value
		if(null !== $this->deps)
			return $this->deps;

		$this->deps = array();

		// Find variables and PCP expressions in value
		preg_match_all('/(<|\$)(\(.*?\)|[\w-]*)/', $this->value, $matches);

		// Loop through found $ tokens
		foreach($matches[0] as $dep)
		{
			// Parse tokens into selector->property pairs or just property names
			preg_match('/\$\((.*?)\s*->\s*(.*?)\s*\)|(\$[\w-]*)|(^<*[\w-]*)/', $dep, $splitdep);

			if($splitdep[4]) // <property-name form
			{
				$scope = $this->selector;

				// Remove a selector from the end for each '<' found
				$upshifts = strlen(preg_replace('/^\s*(<*).*$/', '$1', $splitdep[4]));
				while($upshifts--)
					$scope = preg_replace('/[ >+].*$/', '', $scope, 1);

				$pname = preg_replace('/^<*/', '', $splitdep[4]);

				$this->deps[$dep] = $pcp->state['selectors'][$scope][$pname];

			} else if($splitdep[3]) // $property-name form
			{
				// Broaden scope until we find the named property
				$scope = $this->selector;

				$n = 1;
				while($n)
				{
					if(isset($pcp->state['selectors'][$scope][$splitdep[3]]))
					{
						$this->deps[$dep] =
							$pcp->state['selectors'][$scope]->properties[$splitdep[3]];
						$scope = '';
					} else
						$scope = preg_replace('/[ >+].*$/', '', $scope, 1, $n);
				}
			} else // $(selector->property) form
			{
				if(!($this->deps[$dep] =
					$pcp->state['selectors'][$splitdep[1]]->properties[$splitdep[2]]))
				{
					trigger_error(
						  "{$this->src}:{$this->ln}:{$this->cn}: "
						 ."Reference made to undeclared property '{$splitdep[2]}' "
						 ."in '{$splitdep[1]}'"
						, E_USER_ERROR
					);
					exit(-1);
				}
			}
		}

		// Register ourselves as a dependant on each dependency
		foreach($this->deps as $dep)
			$dep->add_dependant($this);

		return $this->deps;
	}
	static function compute($value, $prev_word = null)
	{
		$literal = '([\d\.]+)(px|em|rad|%)?';

		if(false === strpos($value, array('(', '/', '*', '-', '+')) && $prev_word)
			return "$prev_word(".PCP_Property::compute($value).")";

		return preg_replace(
			array(
				  '/([\w-]*)\s*\((.*)\)/e'			// Reduce parentheses
				, "/{$literal}\s*\/\s*{$literal}/e"	// Division
				, "/{$literal}\s*\*\s*{$literal}/e"	// Multiplication
				, "/{$literal}\s*\-\s+{$literal}/e"	// Subtraction
				, "/{$literal}\s*\+\s*{$literal}/e"	// Addition
				, '/\s/'
			), array(
				  'PCP_Property::compute("$2", "$1")'
				, '$3 != 0 ? ($1 / $3)."$2" : null'
				, '($1 * $3)."$2"'
				, '($1 - $3)."$2"'
				, '($1 + $3)."$2"'
				, ''
			), $value);
	}
}
?>
