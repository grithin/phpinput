<?
namespace Grithin;
use \Grithin\Debug;
class Input{
	public $get;
	public $post;
	public $in;///< get and post combined, with post overriding get

	/**
	With input, must consider that:
	-	error message order may be desired
	-	errors may be tied to field names
	-	an error may be tied to multiple fields

	As a consequence of these considerations, the following structure of the error hash is used:

	[{message:<text message>, fields:[<field name>, ...]}, ...]

	Additional fields may be added according to the functionality of the front end.
	Additionally, the text message part my have replaceable text intended to be replaced by the humanized field names
	*/
	public $errors = [];

	/**
	sets get, post, and in

	@param	options	{
		get:<optional GET array, defaults to $_GET>
		post:<optional POST array, defaults to $_POST>
		in:<optional array of the input that is used, defaults to merged get and post>
		special_json: < whether to look for, and parse "_json" key >
		db: < optional, used for validation, model >
	@note special handling for _json key -> it is parsedd and merged into the array
	*/
	function __construct($options=[]){
		# handle json post
		if(!isset($options['post'])){
			if(!$_POST && substr($_SERVER['CONTENT_TYPE'],0,16) == 'application/json'){
				$_POST = json_decode(file_get_contents('php://input'),true);
			}
		}

		$this->options = $options = array_merge(['special_json'=>false, 'get'=>$_GET, 'post'=>$_POST], $options);
		$this->get = (array)$options['get'];
		$this->post = (array)$options['post'];
		# if there was no post, check for json data

		if(isset($options['in'])){
			$this->in = (array)$options['in'];
		}else{
			$this->in = array_merge($this->get, $this->post);	}

		if($options['special_json'] && $this->in['_json']){
			$this->in['_json'] = \Grithin\Arrays::merge($this->in['_json'], json_decode((string)$this->in['_json'],true));
		}
		if($options['db']){
			$this->db = $options['db'];
		}

		$this->filter = new \Grithin\Filter($this);
		$this->validate = new \Grithin\Validate($this);
		$this->model = new \Grithin\Input\Model(['input'=>$this]);
	}

	///adds message of type error.  See self::message
	function error($details,$fields=[]){
		if(!$details){ # discard empty errors
			return;
		}

		$error = ['fields'=>(array)$fields];
		if(is_array($details)){
			$error = array_merge($error, $details);
		}else{
			$error['message'] = $details;	}

		$this->errors[] = $error;
	}

	///returns all or field specific errors
	function errors($fields=null){
		$fields = (array)$fields;
		$found = [];

		foreach($this->errors as $error){
			if($fields && !array_intersect($fields,$error['fields'])){
				continue;	}
			$found[] = $error;	}
		return $found;
	}

	public $localInstances;///< class instances used for filtering and validating
	function addHandlerInstance($instance,$name){
		$this->localInstances[$name] = $instance;
	}


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

				if($paramsAdditional !== null){
					$paramsAdditional = explode(';',$paramsAdditional);	}	}
			///merge field value param with the user provided params
			if($paramsAdditional){
				$params = array_merge($params,$paramsAdditional);	}
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
					\Grithin\Debug::toss(['type'=>'not_rule_failure', 'detail'=>$rule],'InputException');
				}
			}catch(\InputException $e){
				//this is considered a pass
				if($prefixOptions['not']){
					continue;
				}
				//add error to $this->errors
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
		}
		return ['hasError'=>$fieldError];
	}
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
				return $method;
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

	# add a rule to a rule set (which could be an array or a string)
	# @TODO	rule manipulation functions (add prefix rule, add affix rule, rules to array)
	static function affixWithRule($rule, $rules = []){
		$rules = \Grithin\Arrays::toArray($rules);
		$rules[] = $rule;
		return $rules;
	}
	static function prefixWithRule($rule, $rules = []){
		$rules = \Grithin\Arrays::toArray($rules);
		array_unshift($rules,$rule);
		return $rules;
	}
}
