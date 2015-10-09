# Grithin's PHP Input Tools

## Purpose

Provide a succinct way for common filtering and validating of input

See the Input class inline documentation

## Brief

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




## Example
```php

use \Grithin\Input;


$in = ['bob'=>'sucks'];
$input = new Input(['in'=>$in]);

#validate that the bob value is a float
$rules = ['bob'=>'v.isFloat'];

if($input->handle($rules)){
	die("Yay!");
}else{
	echo 'uh oh...';
	\Grithin\Debug::quit($input->errors);
}
/*
uh oh...

[base:index.php:19] 1: [
	0 : [
		'type' : 'isFloat'
		'fields' : [
			0 : 'bob'
		]
	]
]
*/

```

```php
use \Grithin\Input;

$passingInput = [];

$passingInput[1] = [
		'phone'=>'my phone number is (555)   555-5555.',
		'appointment_time' => 'August 8, 2020',
		'comment' => '    bob is a crazy    '
	];
$passingInput[2] = [
		'phone'=>'my phone number is (555)   555-5555.',
		'comment' => '    bob is a crazy    '
	];

#validate that the bob value is a float
$rules = [
		'phone'=>'v.phone',
		'appointment_time'=>'?!v.filled,v.date,f.datetime', #< '?' means optional, '!' means stop of not validated
		'comment' => 'f.trim'
	];

$input = new Input(['in'=>$passingInput[1]]);
$input->handle($rules);
\Grithin\Debug::out($input->in);
/*
[base:index.php:34] 1: [
	'phone' : '5555555555'
	'appointment_time' : '2020-08-08 00:00:00'
	'comment' : 'bob is a crazy'
]*/

$input = new Input(['in'=>$passingInput[2]]);
$input->handle($rules);
\Grithin\Debug::out($input->in);
/*
[base:index.php:45] 2: [
	'phone' : '5555555555'
	'comment' : 'bob is a crazy'
]
*/


```
