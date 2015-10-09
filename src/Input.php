<?
namespace Grithin;
class Input{
	public $get;
	public $post;
	public $in;///< get and post combined, with post overriding get

	public $messages;///< message related to filtering and validating input

	/**
	sets get, post, and in

	@param	options	{
		get:<optional GET array, defaults to $_GET>
		post:<optional POST array, defaults to $_POST>
		in:<optional array of the input that is used, defaults to merged get and post>
		validate:<optional validate class instance>
		filter:<optional filter class instance>
	@note special handling for _json key -> it is parsedd and merged into the array
	*/
	function __construct($options=[]){
		$options = array_merge(['handleJson'=>true, 'get'=>$_GET, 'post'=>$_POST], $options);
		$this->get = (array)$options['get'];
		$this->post = (array)$options['get'];
		if(isset($options['in'])){
			$this->in = (array)$options['in'];
		}else{
			$this->in = array_merge($this->get, $this->post);	}

		$this->filter = $options['filter'] ? $options['filter'] : new \Grithin\Filter($this);
		$this->validate = $options['validate'] ? $options['validate'] : new \Grithin\Validate($this);
	}
	///handles various ways of posting JSON to the page, and maps them to GET and POST arrays
	/**
	handles a content_type of "application/json", or a '_json' key
	*/
	static function parseJson(){
		if($_GET['_json']){
			$_GET = \Grithin\Arrays::merge($_GET, json_decode((string)$_GET['_json'],true));	}
		if(substr($_SERVER['CONTENT_TYPE'],0,16) == 'application/json'){
			$_POST = json_decode(file_get_contents('php://input'),true);	}
		if($_POST['_json']){
			$_POST = \Grithin\Arrays::merge($_POST, json_decode((string)$_POST['_json'],true));	}
	}

	///adds message of type error.  See self::message
	function error($details,$fields){
		$error = ['fields'=>(array)$fields];
		if(is_array($details)){
			$error = array_merge($details,$error);
		}else{
			$error['message'] = $details;	}

		$this->errors[] = $error;
	}

	///returns all or field specific errors
	function errors($fields=null){
		$fields = (array)$fields;
		$found = [];

		foreach($this->messages as $message){
			if($fields && !array_intersect($fields,$message['fields'])){
				continue;	}
			$found[] = $message;	}
		return $found;
	}

	public $localInstances;///< class instances used for filtering and validating
	function addHandlerInstance($instance,$name){
		$this->localInstances[$name] = $instance;
	}

	public $validatedFields;///< {field:[validation, ...], ...}  Validations passed on each field.  Reset on each validate call
	/**
	@param	rules	string or array
		Rules is an array of rules whos keys map the keys of the input
		Rules are parsed in the order provided

		A rule consists of steps
		Each rule can be an array of steps or a string of steps separated with ','

		Every step consists of at most three parts
		-	prefix
		-	handler
		-	parameters

		The rule looks like one of the following
		-	`handler|param1;param2;param3, handler, handler`
		-	`['handler|param1;param2;param3', handler, handler]`
		-	`[[handler, param1, param2, param2], handler, handler]`
		-	`[[[prefix, callback], param1, param2, param2], handler, handler]`
		Notice, as a string, parameters are separated with ";"


		The prefix can be some combination of the following
		-	"!" to break on error with no more rules for that field should be applied
		-	"!!" to break on error with no more rules for any field should be applied
		-	"?" to indicate the validation is optional, and not to throw an error (useful when combined with '!' => '?!v.filled,email')
		-	"~" to indicate if the validation does not fail, then there was an error
		-	"&" to indicate code should break if there were any previous errors on that field
		-	"&&" to indicate code should break if there were any previous errors on any field in the validate run

		The handler can be one of the following
		-	"f.name" or "filter.name" where 'name' is a method of \Grithin\Filter
		-	"v.name" or "validate.name" where 'name' is a method of \Grithin\Validate
		-	"g.name" or "global.name" where name is a global user function
		-	"l.instance.name" or "local.instance.name" where "instance" is an instance name of $this->localInstances and "name" is the method
		-	"class.method" where "class" is the name of a class and "method" is a name of a static method on that class
		-	An actual callback-able value.  For instance ['\Grithin\Filter','trim']

	@return	false if error, else true
	*/
	function handle($rules){
		$this->validatedFields = [];

		foreach($rules as $field=>$steps){
			unset($fieldValue, $byReference);
			$fieldValue = null;
			if(isset($this->in[$field])){
				$fieldValue = &$this->in[$field];
				$byReference = true;	}

			$result = $this->applyFieldRules($field, $fieldValue, $steps, $hasError);

			if($result['hasError']){
				$hasError = true;}
			//since there was no fields[field], yet the surrogate was manipulated, set the fields[field] to the surrogate
			if(!$byReference && $fieldValue){
				$this->in[$field] = $fieldValue;	}
			//rule had a !!, indicating stop all validation
			if($result['break']){
				break;	}	}
		return !$hasError;
	}

	public $currentField = '';///< name of the current validated
	function applyFieldRules($field, &$value, $steps, $hasError){
		$fieldError = false;

		$this->currentField = $field;

		$steps = \Grithin\Arrays::toArray($steps);
		for($i=0;$i<count($steps);$i++){
			$rule = $steps[$i];
			unset($prefixOptions);
			$params = array(&$value);

			if(is_array($rule)){
				$callback = array_shift($rule);
				if(is_array($callback)){
					list($prefixOptions) = self::rulePrefixOptions($callback[0]);
					$callback = $callback[1];	}
				$paramsAdditional = &$rule;
			}else{
				list($callback,$paramsAdditional) = explode('|',$rule);

				if($paramsAdditional){
					$paramsAdditional = explode(';',$paramsAdditional);	}	}
			///merge field value param with the user provided params
			if($paramsAdditional){
				\Grithin\Arrays::mergeInto($params,$paramsAdditional);	}
			if(!$prefixOptions){
				list($prefixOptions,$callback) = self::rulePrefixOptions($callback);	}
			if($prefixOptions['continuity'] && $fieldError){
				break;	}
			if($prefixOptions['fullContinuity'] && ($fieldError || $hasError)){
				break;	}

			$callback = $this->ruleCallable($callback);

			if(!is_callable($callback)){
				\Grithin\Debug::toss('Rule not callable: '.var_export($steps[$i],true));	}
			try{
				/**
				Thoughts on the principles of the validation callback parameters
					1. The field name is not included, b/c it is rare that the validation cares about the field name.
						If it does, the validation is often a custom function meant specifically for that field.
						And, if it is not such a custom function, the field name can be passed manually by doing so in formulating the rules
						But, just for the hell of it, there is Control::$currentField
					2. Sometimes a field is validated in the context of other fields.  It becomes necessary that the validator can access these other fields.
						Passing in the fields, however, means the majority of functions that don't care would have to change their parameters to account for this useless parameter.
						Now, it can be expected the validate function will not be called within itself, so the static Control::$validationFields should accomodate
				*/
				call_user_func_array($callback,$params);

				if($prefixOptions['not']){
					$prefixOptions['not'] = false;
					\Grithin\Debug::toss('{_FIELD_} Failed to fail a notted rule: '.var_export($rule,true),'InputException');
				}
			}catch(\InputException $e){
				//this is considered a pass
				if($prefixOptions['not']){
					continue;
				}
				//add error to messages
				if(!$prefixOptions['ignoreError']){
					$fieldError = true;
					$content = json_decode($e->getMessage(), true);
					$content = $content ? $content : $e->getMessage();
					call_user_func_array([$this, 'error'], [$content, $field]);
				}
				//full break will break out of all fields
				if($prefixOptions['fullBreak']){
					return ['hasError'=>$fieldError,'break'=>true];
				}
				//break will stop validators for this one field
				if($prefixOptions['break']){
					break;
				}
			}
			$this->validatedFields[$field][] = $callback;
		}
		return ['hasError'=>$fieldError];
	}
	///check if a field has been valiated with a certain validation.
	/**
	@param	validation	the non-shortened validation callback form
	*/
	function fieldValidated($field,$validation){
		$validations = $this->validatedFields[$field];
		if($validations){
			foreach($validations as $v){
				if($v == $validation){
					return true;	}	}	}	}
	function ruleCallable($callback){
		if(is_string($callback)){
			list($type,$method) = explode('.',$callback,2);
			if(!$method){
				$method = $type;
				unset($type);
			}
		}else{
			return $callback;
		}

		if(!$callback){
			\Grithin\Debug::toss('Failed to provide callback for input handler');
		}
		switch($type){
			case 'f':
			case 'filter':
				return [$this->filter,$method];
			break;
			case 'v':
			case 'validate':
				return [$this->validate,$method];
			break;
			case 'l':
			case 'local':
				list($name,$method) = explode('.',$method,2);
				return [$this->localInstances[$name], $method];
			break;
			case 'g':
			case 'global':
				$method;
			break;
			default:
				if($type){
					return [$type,$method];
				}
				return $callback;
			break;
		}
	}
	static function rulePrefixOptions($string){
		//used in combination with !, like ?! for fields that, if not empty, should be validated, otherwise, ignored.
		for($length = strlen($string), $i=0;	$i<$length;	$i++){
			switch($string[$i]){
				case '&':
					if($string[$i + 1] == '&'){
						$i++;
						$options['fullContinuity'] = true;
					}else{
						$options['continuity'] = true;
					}
					break;
				case '?':
					$options['ignoreError'] = true;
					break;
				case '!':
					if($string[$i + 1] == '!'){
						$i++;
						$options['fullBreak'] = true;
					}else{
						$options['break'] = true;
					}
					break;
				case '~':
					$options['not'] = true;
					break;
				default:
					break 2;
			}
		}
		return  [$options,substr($string,$i)];
	}
}
