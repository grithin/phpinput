<?
namespace Grithin;
use Grithin\Debug;
use Grithin\Tool;
use Grithin\Time;
use Grithin\Arrays;
use Grithin\File;
use Grithin\Filter;

class Validate{
	function __construct($input){
		$this->input = $input;
		$this->db = $this->input->options['db'];
	}
	///template messages, but really, view concerns should be kept in the view
	static $errorMessages = array(
			'exists' => 'Missing field {_FIELD_}',
			'filled' => 'Missing field {_FIELD_}',
			'isInteger' => '{_FIELD_} must be an integer',
			'isFloat' => '{_FIELD_} must be a decimal',
			'regex' => '{_FIELD_} must match %s',
			'key' => '{_FIELD_} did not contain an accepted value',
			'in' => '{_FIELD_} did not contain an accepted value',
			'email' => '{_FIELD_} must be a valid email',
			'emailLine' => '{_FIELD_} did not match the format "NAME &lt;EMAIL&gt;',
			'url' => '{_FIELD_} must be a URL',
			'range_max' => '{_FIELD_} must be %s or less',
			'range_min' => '{_FIELD_} must be %s or more',
			'length' => '{_FIELD_} must be a of a length equal to %s',
			'lengthRange_max' => '{_FIELD_} must have a length of %s or less',
			'lengthRange_min' => '{_FIELD_} must have a length of %s or more',
			'date' => '{_FIELD_} must be a date.  Most date formats are accepted',
			'timezone' => '{_FIELD_} must be a timezone',
			'noTagIntegrity' => '{_FIELD_} is lacking HTML Tag context integrity.  That might pass on congress.gov, but not here.',
			'value' => '{_FIELD_} does not match expected value',
			'mime' => '{_FIELD_} must have one of the following mimes: %s',
			'notMime' => '{_FIELD_} must not have any of the following mimes: %s',
			'phone_area' => 'Please include an area code in {_FIELD_}',
			'phone_check' => 'Please check {_FIELD_}',
			'zip' => '{_FIELD_} was malformed',
			'age_max' => '{_FIELD_} too old.  Must be at most %s',
			'age_min' => '{_FIELD_} too recent.  Must be at least %s',
		);
	///true or false return instead of exception
	/**
	@param	method	method  to call
	@param	args...	anything after method param is passed to method

	@note	can be called statically or instancely
	*/
	static function apply(){
		$args = func_get_args();
		$method = array_shift($args);
		try{
			$static = !(isset($this) && get_class($this) == __CLASS__);
			if($static){
				call_user_func_array(array('self',$method),$args);
			}else{
				call_user_func_array(array($this,$method),$args);
			}
			return true;
		}catch(InputException $e){
			return false;
		}
	}
//+	basic validators{
	static function exists(&$value){
		if(!isset($value)){
			Debug::toss(['type'=>'exists'],'InputException');
		}
	}
	static function filled(&$value){
		if(!isset($value) || $value === ''){
			Debug::toss(['type'=>'filled'],'InputException');
		}
	}
	static function isInteger(&$value){
		if(!Tool::isInt($value)){
			Debug::toss(['type'=>'isInteger'],'InputException');
		}
	}
	static function isFloat(&$value){
		if(filter_var($value, FILTER_VALIDATE_FLOAT) === false){
			Debug::toss(['type'=>'isFloat'],'InputException');
		}
	}
	static function value(&$value,$match){
		if($value !== $match){
			Debug::toss(['type'=>'value'],'InputException');
		}
	}
	static function regex(&$value,$regex,$matchModel=null){
		if(!preg_match($regex,$value)){
			if(!$matchModel){
				$matchModel = Tool::regexExpand($regex);
			}
			Debug::toss(['type'=>'regex','detail'=>$matchModel],'InputException');
		}
	}
	static function key(&$value,$array){
		if(!isset($array[$value])){
			Debug::toss(['type'=>'key'],'InputException');
		}
	}
	///see if value is in array.  Either array as 2nd parameter, or taken as remaining parameters
	static function in(&$value){
		$args = func_get_args();
		array_shift($args);
		if(is_array($args[0])){
			$array = $args[0];
		}else{
			$array = $args;
		}
		if(!in_array($value,$array)){
			Debug::toss(['type'=>'in'],'InputException');
		}
	}
	static function email($value){
		if(!filter_var($value, FILTER_VALIDATE_EMAIL)){
			Debug::toss(['type'=>'email'],'InputException');
		}
	}
	//potentially including name: joe johnson <joe@bob.com>
	static function emailLine($value){
		if(!self::check('email',$value)){
			preg_match('@<([^>]+)>@',$value,$match);
			$email = $match[1];
			if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
				Debug::toss(['type'=>'email'],'InputException');
			}
			if(!preg_match('@^[a-z0-9 _\-.]+<([^>]+)>$@i',$value)){
				Debug::toss(['type'=>'emailLine'],'InputException');
			}
		}
	}
	static function url($value){
		if(!filter_var($value, FILTER_VALIDATE_URL)){
			Debug::toss(['type'=>'url'],'InputException');
		}
		//the native filter doesn't even check if there is at least one dot (tld detection)
		if(strpos($value,'.') == false){
			Debug::toss(['type'=>'url'],'InputException');
		}
	}
	static function range($value,$min=null,$max=null){

		if($max !== '' && $max !== null && $value > (int)$max){
			Debug::toss(['type'=>'range_max', 'detail'=>$max],'InputException');
		}
		if($min !== '' && $min !== null && $value < (int)$min){
			Debug::toss(['type'=>'range_min', 'detail'=>$min],'InputException');
		}
	}
	static function length($value,$length){
		$actualLength = strlen($value);
		if($actualLength != $length){
			Debug::toss(['type'=>'length', 'detail'=>$length],'InputException');
		}
	}
	static function lengthRange($value,$min=null,$max=null){
		$actualLength = strlen($value);
		if(Tool::isInt($max) && $actualLength > $max){
			Debug::toss(['type'=>'lengthRange_max', 'detail' => $max],'InputException');
		}
		if(Tool::isInt($min) && $actualLength < $min){
			Debug::toss(['type'=>'lengthRange_min', 'detail' => $min],'InputException');
		}
	}
	static function timezone($value){
		try {
			new \DateTimeZone($value);
		} catch(\Exception $e) {
			Debug::toss(['type'=>'timezone'],'InputException');
		}
	}

	static function date($value){
		try{
			new Time($value);
		}catch(\Exception $e){
			Debug::toss(['type'=>'date'],'InputException');
		}
	}
	/**
	@param	mimes	array of either whole mimes "part/part", or the last part of the mime "part"
	*/
	static function mime($v,$name,$mimes){
		$mimes = Arrays::toArray($mimes);
		$mime = File::mime($_FILES[$name]['tmp_name']);
		foreach($mimes as $matchMime){
			if(preg_match('@'.preg_quote($matchMime).'$@',$mime)){
				return true;
			}
		}
		$mimes = implode(', ',$mimes);
		Debug::toss(['type'=>'mime', 'detail' => $mimes],'InputException');
	}
	/**
	@param	mimes	array of either whole mimes "part/part", or the last part of the mime "part"
	*/
	static function notMime($v,$name,$mimes){
		$mimes = Arrays::toArray($mimes);
		$mime = File::mime($_FILES[$name]['tmp_name']);
		foreach($mimes as $matchMime){
			if(preg_match('@'.preg_quote($matchMime).'$@',$mime)){
				$mimes = implode(', ',$mimes);
				Debug::toss(['type'=>'notMime', 'detail' => $mimes],'InputException');
			}
		}
		return true;
	}
//+	}
	static function csrf($value){
		$csrfToken = $_SESSION['csrfToken'];
		unset($_SESSION['csrfToken']);
		if(!$csrfToken){
			Debug::toss(['type'=>'no_csrf'],'InputException');
		}elseif(!$value){
			Debug::toss(['type'=>'missing_csrf'],'InputException');
		}elseif($value != $csrfToken){
			Debug::toss(['type'=>'csrf_mismatch'],'InputException');
		}
	}
	///matches value against (string)callable return value
	/**
		@param	callable	function($value){}
	*/
	static function dynamicValue(&$value,$callable){
		if($value != (string)call_user_func($callable,$value)){
			Debug::toss(['type'=>'value_mismatch'],'InputException');
		}
	}

	static function basicText(&$value){
		Filter::trim($value);
		Filter::conditionalNl2Br($value);
		Filter::stripTags($value,'br,a,b,i,u,ul,li,ol,p','href');
		Filter::trim($value);
		self::filled($value);
		self::htmlTagContextIntegrity($value);
	}
	static function title(&$value){
		Filter::regex($value,'@[^a-z0-9_\- \']@i');
		self::lengthRange($value,2);
	}
	static function ip4(&$value){
		self::regex($value,'@[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}@','ip4 format');
	}
	static function humanBirthdate(&$value){
		self::date($value);
		self::age($value,18,130);
	}
	static function password(&$value){
		self::lengthRange($value,3,50);
	}
	static function name(&$value){
		if(!preg_match('@^[a-z \']{2,}$@i',$value)){
			Debug::toss(['type'=>'not_a_name'],'InputException');
		}
	}
	static function zip($value){
		if (!preg_match("/^([0-9]{5})(-[0-9]{4})?$/i",$value)) {
			Debug::toss(['type'=>'zip'],'InputException');
		}
	}
	static function phone(&$value){
		Filter::digits($value,'digits');
		self::filled($value);

		if(strlen($value) == 11 && substr($value,0,1) == 1){
			$value = substr($value,1);
		}
		if(strlen($value) == 7){
			Debug::toss(['type'=>'phone_area'],'InputException');
		}

		if(strlen($value) != 10){
			Debug::toss(['type'=>'phone_check'],'InputException');
		}
	}
	static function international_phone(&$value){
		Filter::digits($value,'digits');
		self::filled($value);

		if(strlen($value) < 11){
			Debug::toss(['type'=>'phone_international'],'InputException');
		}
		if(strlen($value) > 14){
			Debug::toss(['type'=>'phone_international__over'],'InputException');
		}
	}


	static function age($value,$min=null,$max=null){
		$time = new Time($value);
		$age = $time->diff(new Time('now'));
		if(Tool::isInt($max) && $age->y > $max){
			Debug::toss(['type'=>'age_max', 'detail' => $max],'InputException');
		}
		if(Tool::isInt($min) && $age->y < $min){
			Debug::toss(['type'=>'age_min', 'detail' => $min],'InputException');
		}
	}
	static function htmlTagContextIntegrity($value){
		self::$tagHierarchy = [];
		preg_replace_callback('@(</?)([^>]+)(>|$)@',array(self,'htmlTagContextIntegrityCallback'),$value);
		//tag hierarchy not empty, something wasn't closed
		if(self::$tagHierarchy){
			Debug::toss(['type'=>'noTagIntegrity'],'InputException');
		}
	}
	static $tagHierarchy = [];
	static function htmlTagContextIntegrityCallback($match){
		preg_match('@^[a-z]+@i',$match[2],$tagMatch);
		$tagName = $tagMatch[0];

		if($match[1] == '<'){
			//don't count self contained tags
			if(substr($match[2],-1) != '/'){
				self::$tagHierarchy[] = $tagName;
			}
		}else{
			$lastTagName = array_pop(self::$tagHierarchy);
			if($tagName != $lastTagName){
				Debug::toss(['type'=>'noTagIntegrity'],'InputException');
			}
		}
	}

	function db_in_table(&$value,$table,$field='id'){
		if(!$this->db->check($table,array($field=>$value))){
			Debug::toss(['type'=>'inTable'],'InputException');
		}
	}
	function db_not_in_table(&$value,$table,$field='id'){
		if($this->db->check($table,array($field=>$value))){
			Debug::toss(['type'=>'notInTable'],'InputException');
		}
	}


	///check if create/update will collide with existing row using $control->id and $control->in
	///@note assumes primary control
	function checkUniqueKeys($value, $table,$type,$id=null){
		$indices = $this->db->indices($table);
		foreach($indices as $name => $key){
			if($key['unique']){
				if($name != 'PRIMARY'){
					foreach($key['columns'] as $column){
						if(!isset($this->input->in[$column]) || $this->input->in[$column] === null){
							//null indices can overlap
							continue 2;
						}
					}
					$where = Arrays::extract($key['columns'],$this->input->in);
					if($type != 'create' && $id){
						$where['id?<>'] = $id;
					}
					if($this->db->check($table,$where)){
						Debug::toss(['type'=>'record_not_unique','detail'=>$key['columns']],'InputException');
					}
				}
			}
		}
	}
}
