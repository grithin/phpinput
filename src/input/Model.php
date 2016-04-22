<?
namespace Grithin\Input;
use \Grithin\Debug; #< convenience

class Model{
	/*
	@param	options	{
			tables: < table info to use >
			input: < instance of \Grithin\Input >
			table: < table used if not specified >
			not_filter_time:	< whether to filter time >,
		}
	*/
	function __construct($input, $options=[]){
		$this->db = $input->options['db'];
		$this->tables = (array)$options['tables'];
		$this->default_table = $options['table'];
	}
	function table_info($table_name=''){
		$table_name = $table_name ? $table_name : $this->default_table;

		if(!$this->tables[$table_name]){
			$this->tables[$table_name] = $this->db->tableInfo($table_name);
		}
		return $this->tables[$table_name];
	}
	function create($table_name=''){
		$table_name = $table_name ? $table_name : $this->default_table;

		$base = $this->standard($table_name);
		$create = $this->create_specific($table_name);

		foreach($create as $field=>$validaters){
			# replace base validaters with particular create validaters
			if($validaters == '!v.filled'){
				$base[$field] = \Grithin\Input::prefixWithRule($validaters,$base[$field]);
			}else{
				$base[$field] = $validaters;
			}
		}
		return $base;
	}
	function validate_create($table_name=''){
		return $this->input->handle($this->create($table_name));
	}

	function update($table_name=''){
		$table_name = $table_name ? $table_name : $this->default_table;

		$base = $this->standard($table_name);
		$table_info = $this->table_info($table_name);
		$date_column = $table_info['columns']['updated'];
		if($date_column){
			if($date_column['type'] == 'date'){
				$base['updated'] = 'f.remove,f.date';
			}elseif($date_column['type'] == 'datetime'){
				$base['updated'] = 'f.remove,f.datetime';
			}
		}
		return $base;
	}
	function validate_update($table_name=''){
		return $this->input->handle($this->update($table_name));
	}


	function create_specific($table_name=''){
		$table_name = $table_name ? $table_name : $this->default_table;

		$validaters	= [];
		$table_info = $this->table_info($table_name);

		foreach($table_info['columns'] as $field=>$column_info){
			if($column_info['autoIncrement']){
				$validaters[$field][] = 'f.remove';
			}elseif(!$column_info['nullable'] && $column_info['default'] === null){
				/**
				(not nullable) + (default = null) interpretted to mean field must be filled.
				*/
				if(
					($field == 'created' || $field == 'updated') &&
					($column_info['type'] == 'datetime' || $column_info['type'] == 'date')
				){
					if($column_info['type'] == 'datetime'){
						$validaters[$field] = 'f.remove,f.datetime';
					}else{
						$validaters[$field] = 'f.remove,f.date';
					}

					continue;
				}

				$validaters[$field][] = '!v.filled';
			}
		}
		return $validaters;
	}
	function validate_create_specific($table_name=''){
		return $this->input->handle($this->create_specific($table_name));
	}

	function standard($table_name=''){
		$table_name = $table_name ? $table_name : $this->default_table;

		$validaters	= [];
		$table_info = $this->table_info($table_name);

		foreach($table_info['columns'] as $field=>$column_info){
			$validaters[$field] = $this->field($field, $table_name);
		}

		return $validaters;
	}
	function validate_standard($table_name=''){
		return $this->input->handle($this->standard($table_name));
	}


	function field($field, $table_name=''){
		$table_name = $table_name ? $table_name : $this->default_table;

		$table_info = $this->table_info($table_name);
		$column_info = $table_info['columns'][$field];

		$validaters = [];

		//speical handling for 'created' and 'updated'
		if(
			($field == 'created' || $field == 'updated') &&
			($column_info['type'] == 'datetime' || $column_info['type'] == 'date')
		){
			return ['f.remove'];
		}

		$validaters[] = 'f.toString';
		if(!$column_info['nullable'] && !$column_info['autoIncrement']){
			if($column_info['default'] === null){
				//column must be present
				$validaters[] = '!v.exists';
			}else{
				//there's a default, when missing, set to default
				$validaters[] = ['f.unsetMissing'];
				$validaters[] = ['?!v.filled'];
			}
		}else{
			//for nullable columns, empty inputs (0 character strings) are null
			$validaters[] = array('f.toDefault',null);

			//column may not be present.  Only validate if present
			$validaters[] = '?!v.filled';
		}
		switch($column_info['type']){
			case 'datetime':
			case 'timestamp':
				$validaters[] = '!v.date';
				//since f.datetime converts using timezone, can't run it twice without corrupting data if client has different tz
				if(!$this->options['not_filter_time']){
					$validaters[] = 'f.datetime';
				}

			break;
			case 'date':
				$validaters[] = '!v.date';
				//since f.date converts using timezone, can't run it twice without corrupting data if client has different tz
				if(!$this->options['not_filter_time']){
					$validaters[] = 'f.toDate';
				}
			break;
			case 'text':
				if($column_info['limit']){
					$validaters[] = '!v.lengthRange|0;'.$column_info['limit'];
				}
			break;
			case 'int':
				if($column_info['limit'] == 1){//boolean value
					$validaters[] = 'f.toBool';
					$validaters[] = 'f.toInt';
				}else{
					$validaters[] = 'f.trim';
					$validaters[] = '!v.isInteger';
				}
			break;
			case 'decimal':
			case 'float':
				$validaters[] = 'f.trim';
				$validaters[] = '!v.isFloat';
			break;
		}

		return $validaters;
	}
	function column_names($table_name=''){
		$table_name = $table_name ? $table_name : $this->default_table;

		return array_keys($this->table_info($table_name)['columns']);
	}
	# get the values that are present in the input that correspond to columns
	function values($table_name='', $input=null){
		if($input === null){
			$input = $this->input->in;
		}

		$columns = $this->column_names($table_name);

		$values = \Grithin\Arrays::extract($columns, $input, ($v = []), false);
		return $values;
	}
}