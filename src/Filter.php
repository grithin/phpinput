<?
namespace Grithin;
use Grithin\Time;
use Grithin\Arrays;
use Grithin\Debug;

/**
@note	need  to define currentInputKey to use some functions
@note	naming convention: prefix language conflicting names with "to"
*/

///
class Filter{
	function __construct($input){
		$this->input = $input;
	}

	static $env = [];
	static function configure($env=[]){
		if(!$env['targetTimezone'] && $_ENV['timezone']){
			$env['targetTimezone'] = $_ENV['timezone'];	}
		$env = Arrays::merge(['inputTimezone'=>'UTC','targetTimezone'=>'UTC'], $_ENV, $env);
		self::$env = $env;
	}

	///potentially some input vars are arrays.  To prevent errors in functions that expect field values to be strings, this function is here.
	static function makeString(&$value){
		if(is_array($value)){
			$valueCopy = $value;
			$value = self::getString(array_shift($valueCopy));
		}
	}
	///note, this can be a waste of resources; a reference $value going in is remade on assignment from the return of this function, so use makeString on references instead
	static function getString($value){
		if(is_array($value)){
			return self::getString(array_shift($value));
		}
		return $value;
	}

	///filter to boolean
	static function toBool(&$value){
		return $value = (bool)$value;
	}
	///filter to integer
	static function toInt(&$value){
		return $value = (int)$value;
	}
	///filter to absolute integer
	static function abs(&$value){
		return $value = abs($value);
	}
	///filter to float
	static function decimal(&$value){
		return $value = (float)$value;
	}
	///filter all but digits
	static function digits(&$value){
		return $value = preg_replace('@[^0-9]@','',$value);
	}
	static function regex(&$value,$regex,$newValue=''){
		return $value = preg_replace($regex,$newValue,$value);
	}
	static function url(&$value){
		$value = trim($value);
		if(substr($value,0,4) != 'http'){
			$value = 'http://'.$value;
		}
		return $value;
	}
	///get the deepest value of the first elements
	static function toString(&$value){
		while(is_array($value)){
			$value = array_shift($value);	}
		return $value;	}
	static function toArray(&$value){
		$value = (array)$value;	}
	///diminish arbitrarily deep array into a flat array using toString
	static function arrayOfDepth(&$value, $depth=1){
		if(!$depth){
			return $value = self::toString($value);	}
		$value = (array)$value;
		foreach($value as &$v){
			self::arrayOfDepth($v, $depth-1);	}
		return $value;	}


	static function name(&$value){
		self::trim($value);
		self::regex($value,'@ +@',' ');
		$value = preg_split('@, *@', $value);
		array_reverse($value);
		$value = implode(' ',$value);
		self::regex($value,'@[^a-z \']@i');
		return $value;
	}
	static function trim(&$value){
		return $value = trim($value);
	}
	static function date(&$value,$inOutTz=null){
		return $value = (new Time($value,self::$env['inputTimezone']))->setZone(self::$env['targetTimezone'])->date();
	}
	static function datetime(&$value,$inOutTz=null){
		return $value = (new Time($value,self::$env['inputTimezone']))->setZone(self::$env['targetTimezone'])->datetime();
	}
	static function toDefault(&$value,$default){
		if($value === null || $value === ''){
			$value = $default;
		}
		return $value;
	}
	static function email(&$value){
		preg_match('@<([^>]+)>@',$value,$match);
		if(!$match){
			return $value;
		}
		$email = $match[1];
		return $email;
	}
	static function br2Nl(&$value){
		return $value = preg_replace('@<br */>@i',"\n",$value);
	}
	///on fields which may contain html, if they contain certain html, don't do nl to br
	static function conditionalNl2Br(&$value){
		if(!preg_match('@<div|<p|<table@',$value)){
			$value = preg_replace('@\r\n|\n|\r@','<br/>',$value);//nl2br doesn't remove newlines
		}
		return $value;
	}

	static $stripTagsAllowableTags;
	static $stripTagsAllowableAttributes;
	//doesn't verify start end tag context integrity.  Use validator htmlTagContextIntegrity
	static function stripTags(&$value,$allowableTags=null,$allowableAttributes=null){
		self::$stripTagsAllowableTags = Arrays::toArray($allowableTags);
		self::$stripTagsAllowableAttributes = $allowableAttributes;
		return $value = preg_replace_callback('@(</?)([^>]+)(>|$)@',array(self,'stripTagsCallback'),$value);
	}
	static function stripTagsCallback($match){
		preg_match('@^[a-z]+@i',$match[2],$tagMatch);
		if($tagMatch){
			$tag = $match[0];
			$tagName = $tagMatch[0];
			if(!in_array($tagName,self::$stripTagsAllowableTags)){
				return '';
			}

			if($match[1] == '<'){
				//allow some appropriate attributes on opening tags
				$attributes = self::getAttributes($match[0],self::$stripTagsAllowableAttributes);

				if(substr($match[2],-1) == '/'){
					$close = ' />';
				}else{
					$close = '>';
				}
				if($callback){
					call_user_func_array($callback,array(&$tagMatch[0],&$attributes));
				}
				return '<'.$tagMatch[0].($attributes ? ' '.implode(' ',$attributes) : '').$close;
			}else{
				if($callback){
					call_user_func_array($callback,array(&$tagMatch[0]));
				}
				return '</'.$tagMatch[0].'>';
			}
		}else{
			return '';
		}
	}
	static function getAttributes($tag,$attributes){
		$attributes = Arrays::toArray($attributes);
		$collected = array();
		foreach($attributes as $attribute){
			preg_match('@'.$attribute.'=([\'"]).+?\1@i',$tag,$match);
			if($match){
				$collected[] = $match[0];
			}
		}
		return $collected;
	}
	static function currency($value){
		$value = preg_replace('@[^\-0-9.]@','',$value);
		$value = round((float)$value,2);
	}
	static function value(&$value,$newValue){
		$value = $newValue;	}
	static function reference(&$value,&$newValue){
		$value = &$newValue;	}
	static function dynamicValue(&$value,$callable){
		$value = call_user_func($callable,$value);	}


	//++ INSTANCE! functions  {
	///place the value into a new input key
	function rekey($value,$key){
		$this->input->in[$key] = $value;
	}
	///unsets a field if missing from the fields array
	function unsetMissing($value, $key){
		if($value === null || $value === ''){
			unset($this->input->in[$key]);	}
	}
	///removes a field
	function remove($value, $key){
		unset($this->input->in[$key]);	}
	///turns the fields into strings
	function toStrings(){
		foreach($this->input->in as $k=>&$v){
			if(is_array($v)){
				self::makeString($v);	}	}
	}
	//++ }
}
Filter::configure();