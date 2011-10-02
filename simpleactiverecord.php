<?
/*

SimpleActiveRecord - A simple ORM classes for > PHP 5.2
Copyright (C) 2010 Håkon Nessjøen <haakon.nessjoen@gmail.com>

This library is free software; you can redistribute it and/or
modify it under the terms of the GNU Lesser General Public
License as published by the Free Software Foundation; either
version 2.1 of the License, or (at your option) any later version.

This library is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
Lesser General Public License for more details.

You should have received a copy of the GNU Lesser General Public
License along with this library; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA

*/

class InvalidDbAdapterException extends Exception {}
class SqlErrorException extends Exception {}

// Define defaults for SimpleDbAdapters
class SimpleDbAdapter {
	static protected $dbh;

	public function connect($hostname, $user, $password, $database) {
		throw new InvalidDbAdapterException(get_class($this) . ' is missing a connect() definition');
	}

	public function runQuery($recordObject, $sql) {
		throw new InvalidDbAdapterException(get_class($this) . ' is missing a runQuery() definition');
	}

	public function escapeString($string) {
		return "'" . addslashes($string) . "'";
	}

	public function escapeField($field) {
		return $field;
	}

	public function escapeTablename($tablename) {
		return $tablename;
	}

	public function getTableFields() {
		return;
	}

	public function fetchRow($recordObject, $resource = '') {
		throw new InvalidDbAdapterException(get_class($this) . ' is missing a fetchRow() definition');
	}

	public function lastInsertId($resource='') {
		throw new InvalidDbAdapterException(get_class($this) . ' is missing a lastInsertId() definition');
	}
}

/*
 * Mysql SimpleDbAdapter for SimpleActiveRecord
*/
class mysqlAdapter extends SimpleDbAdapter {

	public function connect($hostname, $user, $password, $database) {
		self::$dbh = mysql_pconnect($hostname, $user, $password);
		if (mysql_error()) {
			throw new SqlErrorException(mysql_error());
		}
		mysql_select_db($database, self::$dbh);
		if (mysql_error()) {
			throw new SqlErrorException(mysql_error());
		}
		return true;
	}

	public function runQuery($recordObject, $sql) {
		$recordObject->lastSqlResource = mysql_query($sql, self::$dbh);
		if (mysql_error()) {
			throw new SqlErrorException(mysql_error(self::$dbh));
		}
		return $recordObject->lastSqlResource;
	}

	public function escapeString($string) {
		return "'" . mysql_real_escape_string($string, self::$dbh) . "'";
	}

	public function escapeField($field) {
		$field = str_replace("`","",$field);
		return '`' . $field . '`';
	}

	public function escapeTablename($tablename) {
		return $this->escapeField($tablename);
	}

	private function mysqlTypeToARType($mysqlType) {
		if (preg_match("/^([^(]+)/", $mysqlType, $match)) {
			switch (strtolower($match[1])) {
				case 'tinyint':
				case 'smallint':
				case 'mediumint':
				case 'int':
				case 'bigint':
				case 'float':
				case 'double':
				case 'decimal':
					return 'int';

				default:
					return 'string';
			}
		}
		return 'string';
	}

	public function getTableFields($recordObject) {
		$fields = Array();
		$sqlResource = $this->runQuery(&$recordObject, 'EXPLAIN ' . $this->escapeTablename($recordObject->getTablename()));

		if (mysql_num_rows($sqlResource)) {
			while ($row = mysql_fetch_array($sqlResource)) {
				$fields[$row[0]] = array(
					'type' => $this->mysqlTypeToARType($row[1]),
					'null' => strtolower($row[2]) == 'yes'
				);
			}
		}
		return $fields;
	}

	public function fetchRow($recordObject, $resource = '') {
		if (empty($resource)) {
			$resource = $recordObject->lastSqlResource;
		}

		return mysql_fetch_assoc($resource);
	}

	public function lastInsertId($resource) {
		return mysql_insert_id(self::$dbh);
	}
}

class SimpleDbAdapterWrapper {
	static $adapter;
	public $lastSqlResource;
	public $lastSqlQuery;

	static public function setAdapter($name) {
		self::$adapter = new $name();
	}

	static public function connect($hostname, $user, $password, $database = '') {
		return self::$adapter->connect($hostname, $user, $password, $database);
	}

	protected function runQuery($query) {
		$this->lastSqlQuery = $query;
		return self::$adapter->runQuery(&$this, $query);
	}

	protected function escapeField($field) {
		return self::$adapter->escapeField($field);
	}

	protected function escapeString($query) {
		return self::$adapter->escapeString($query);
	}

	protected function escapeTablename($query) {
		return self::$adapter->escapeTablename($query);
	}

	protected function getTableFields() {
		return self::$adapter->getTableFields(&$this);
	}

	protected function fetchRow($sqlResource) {
		return self::$adapter->fetchRow(&$this, $sqlResource);
	}

	protected function lastInsertId($sqlResource='') {
		return self::$adapter->lastInsertId($sqlResource);
	}
}

class ARExpect {

	// key:value,value,value,key:value
	public static function expectAssocArray($mixed) {
		$rows = self::expectArray($mixed);
		$resultArray = array();

		foreach ($rows as $existingkey => $row) {

			$split = preg_split("/:/", $row);
			$key   = $split[0];

			if (isset($split[1])) {
				$value = $split[1];
			}

			if (isset($value)) {
				$resultArray[$key] = $value;
			} elseif (!is_numeric($existingkey)) {
				$resultArray[$existingkey] = $key;
			} else {
				$resultArray[$key] = $key;
			}
		}
		return $resultArray;
	}

	// row1,row2
	public static function expectArray($mixed) {
		if (is_array($mixed)) {
			return $mixed;
		}

		return preg_split("/\s*,\s*/", $mixed);
	}

	public static function expectString($mixed) {
		if (is_array($mixed)) {
			return join(', ', $mixed);
		}

		if (is_object($mixed)) {
			throw new Exception('Unexpected object, was expecting a String');
		}

		return $mixed;
	}

}

class SQLExpression {
	private $expression;

	function __construct($expression) {
		$this->expression = $expression;
	}

	function __toString() {
		return $this->expression;
	}
}

class SimpleActiveRecord extends SimpleDbAdapterWrapper {
	protected $isNew;
	protected $db;
	protected $_adapter;
	protected static $_cache = array();
	
	protected $primaryKey = 'id';
	protected $tableName;
	protected $serialize;
	protected $fields;
	public $belongsTo = array();
	public $hasMany = array();

	public function __construct() {
		/*
		* If this were a larger project this function should
		* have automatically pluralized the table name correctly.
		* Instead, we want you to use singular table names.
		*/
		if (empty($this->tableName)) {
			$this->tableName = strtolower(get_class($this));
		}

		$this->belongsTo = ARExpect::expectAssocArray($this->belongsTo);
		$this->hasMany = ARExpect::expectAssocArray((array)$this->hasMany);

		$this->serialize = ARExpect::expectArray($this->serialize);

		$this->_adapter = &self::$adapter;

		if ($this->getCache('$tableFields$') === null) {
			$this->fields = $this->getTableFields();
			$this->setCache('$tableFields$', $this->fields);
		} else {
			$this->fields = $this->getCache('$tableFields$');
		}

		if (func_num_args() == 1) {
			$arg = func_get_arg(0);

			if (is_array($arg)) {
				$this->initWithArray($arg);
			} else {
				$this->initFromId($arg);
			}

		} elseif (func_num_args() == 2) {
			$arg1 = func_get_arg(0);
			$arg2 = func_get_arg(1);
			$this->initFromKVP($arg1, $arg2);
		} elseif (func_num_args() == 0) {
			$this->isNew = true;
		} else {
			throw new Exception('Invalid number of arguments to initialization of ' . get_class($this));
		}
	}

	private function initObject($new) {
		$this->isNew = $new;

		if (!$this->isNew) {
			$this->unserializeFields();
		}

		$this->afterLoad();
	}

	private function unserializeFields() {
		foreach ((array)$this->serialize as $field) {
			if (!empty($this->$field)) {
				$this->$field = unserialize($this->$field);
			}
		}
	}

	private function serializeFields() {
		foreach ((array)$this->serialize as $field) {
			if (!empty($this->$field)) {
				$this->$field = serialize($this->$field);
			}
		}
	}

	private function initWithArray($assocArray) {
		$this->initFromAssocArray($assocArray);

		$this->initObject(true);
	}

	private function initFromId($id) {
		$where = $this->generateWhereQuery(array( $this->primaryKey => $id ));
		$sql = $this->generateSelectQuery($where, '', '', 1);

		$sqlResource = $this->runQuery($sql);
		$this->initFromSqlResource($sqlResource);

		$this->initObject(false);
	}

	private function initFromKVP($key, $value) {
		$where = $this->generateWhereQuery(array( $key => $value ));
		$sql = $this->generateSelectQuery($where, '', '', 1);

		$sqlResource = $this->runQuery($sql);
		$this->initFromSqlResource($sqlResource);

		$this->initObject(false);
	}

	private function initFromSqlResource($sqlResource) {
		$data = $this->fetchRow($sqlResource);
		$this->initFromAssocArray($data);
	}

	private function initFromAssocArray($assocArray) {
		foreach ((array)$assocArray as $key => $value) {
			$this->$key = $value;
		}
	}

	public function getTableName() {
		return $this->tableName;
	}

	public function getPrimaryKey() {
		return $this->primaryKey;
	}

	public function getId() {
		if (isset($this->{$this->primaryKey}))
			return $this->{$this->primaryKey};
		return null;
	}

	private function getCache($name) {
		if (isset(self::$_cache[get_class($this).'_'.$name]))
			return self::$_cache[get_class($this).'_'.$name];

		return null;
	}

	private function setCache($name, $value) {
		self::$_cache[get_class($this).'_'.$name] = $value;
	}

	public function __set($setKey,$setValue) {
		if (is_object($setValue) && is_subclass_of($setValue, 'SimpleActiveRecord')) {
			if (isset($this->belongsTo[$setKey])) {
				$value = $this->belongsTo[$setKey];
				if (strpos($value, ':') !== false) {
					list($key, $class) = split(':', $value);
				} else {
					$key = $setKey . '_id';
					$class = $value;
				}
				$this->{$key} = $setValue->getId();
			} elseif (isset($this->hasMany[$setKey])) {
				$this->__set($setKey, Array($setValue));
			}
		} elseif (isset($this->hasMany[$setKey]) && is_array($setValue)) {
			$value = $this->hasMany[$setKey];
			if (strpos($value, ':') !== false) {
				list($key, $className) = split(':', $value);
			} else {
				$key = $this->tableName . '_id';
				$className = $value;
			}
			$class = new $className();
			$removeList = $class->findBy($key, $this->getId());
			foreach ((array)$removeList as $remove) {
				$remove->{$key} = null;
				$remove->save();
			}

			foreach ((array)$setValue as $add) {
				if (is_object($add) && is_subclass_of($add, 'SimpleActiveRecord') && get_class($add) == $className) {
					$add->{$key} = $this->getId();
					$add->save();
				}
			}
		} else {
			$this->{$setKey} = $setValue;
		}
	}

	public function __get($name) {

		if ($this->getCache($name) !== null) {
			return $this->getCache($name);
		}

		if (isset($this->belongsTo[$name])) {
			$value = $this->belongsTo[$name];
			if (strpos($value, ':') !== false) {
				list($key, $class) = split(':', $value);
			} else {
				$key = $name . '_id';
				$class = $value;
			}

			if (isset($this->$key) && !empty($this->$key)) {
				$obj = new $class($this->$key);
				$this->setCache($name, $obj);
				return $obj;
			}
		}

		if (isset($this->hasMany[$name])) {
			$value = $this->hasMany[$name];
			if (strpos($value, ':') !== false) {
				list($key, $class) = split(':', $value);
			} else {
				$key = $this->tableName . '_id';
				$class = $value;
			}

			$obj = new $class();
			$result = $obj->findBy($key, $this->{$this->primaryKey});
			$this->setCache($name, $result);
			return $result;
		}
	
	}

	public function setAttributes($assocArray) {
		$this->initWithAssocArray($assocArray);
	}

	public function getAttributes() {
		$out = Array();
		foreach ((array)$this->fields as $key => $value) {
			$out[$key] = $this->$key;
		}
		return $out;
	}

	public function findFirstBy($key, $value, $order = '', $group = '') {
		$query = $this->generateWhereQuery(array($key => $value));

		return $this->find($query, $order, $group, 1);
	}

	public function findBy($key, $value, $order = '', $group = '', $limit = '') {
		$query = $this->generateWhereQuery(array($key => $value));

		return $this->find($query, $order, $group, $limit);
	}

	public function findFirst($query, $order = '', $group = '') {
		return $this->find($query, $order, $group, 1);
	}

	public function find($query, $order, $group, $limit) {
		$rows = array();
		$className = get_class($this);
		$sql = $this->generateSelectQuery($query, $order, $group, $limit);

		$sqlResource = $this->runQuery($sql);
		while ($assocArray = $this->fetchRow($sqlResource)) {
			$newRecord = new $className();
			$newRecord->initFromAssocArray($assocArray);
			$newRecord->isNew = false;

			if ($limit == 1)
				return $newRecord;

			$rows[] = $newRecord;
		}
		return $rows;
	}

	public function save() {
		$setQuery = Array();
		$saveFields = $this->fields;

		if (!$this->beforeSave())
			break;

		$this->serializeFields();

		foreach ($saveFields as $fieldName => $fieldInfo) {
			$setQuery[$fieldName] = $this->$fieldName;
		}
		unset($setQuery[$this->primaryKey]);

		if (!$this->isNew) {
			$query = $this->generateUpdateQuery($setQuery, $this->generateWhereQuery(array( $this->primaryKey => $this->{$this->primaryKey} )));
			$this->runQuery($query);
			$this->unserializeFields();
		} else {
			$query = $this->generateInsertQuery($setQuery);
			$this->runQuery($query);
			$this->initFromId($this->lastInsertId());
		}
	}

	public function destroy() {
		if (!$this->beforeDestroy())
			break;

		$where = $this->generateWhereQuery(array( $this->primaryKey => $this->{$this->primaryKey} ));
		$sql = $this->generateDeleteQuery($where);
		$this->runQuery($sql);
		$this->isNew = true;
	}

	public function destroyBy($key,$value) {
		$objects = $this->findBy($key, $value);

		foreach ((array)$objects as $object) {
			$object->destroy();
		}
	}

	public function deleteBy($key, $value) {
		$where = $this->generateWhereQuery(array( $key => $value ));
		$sql = $this->generateDeleteQuery($where);
		$this->runQuery($sql);
	}

	private function escapeFieldValue($field, $value) {
		if (!isset($this->fields[$field]))
			return null;

		if (is_object($value)) {
			return (string)$value;
		}

		if ($value === null && $this->fields[$field]['null'] == true) {
			return 'NULL';
		}

		if ($this->fields[$field]['type'] == 'int') {
			if ($value === null) {
				return 0;
			}
			return (float)$value;
		} else {
			if ($value === null) {
				return $this->escapeString('');
			}
			return $this->escapeString($value);
		}
	}

	private function escapeAssocDataToArray($data) {
		$query = array();
		foreach ((array)$data as $key => $value) {
			$query[] = $this->escapeField($key) . ' = ' . $this->escapeFieldValue($key, $value);
		}
		return $query;
	}

	private function escapeAssocData($data) {
		$query = array();
		foreach ((array)$data as $key => $value) {
			$query[$this->escapeField($key)] = $this->escapeFieldValue($key, $value);
		}
		return $query;
	}

	private function generateUpdateQuery($fields, $where) {
		$escapedData = $this->escapeAssocDataToArray($fields);
		$sql = 'UPDATE ' . $this->escapeTablename($this->tableName) . ' SET ' . join(', ', $escapedData) . ' WHERE ' . $where . ' LIMIT 1';
		return $sql;
	}

	private function generateInsertQuery($fields) {
		$fields[$this->primaryKey] = null;

		$escapedData = $this->escapeAssocData($fields);
		$sql = 'INSERT INTO ' . $this->escapeTablename($this->tableName) . ' (' . join(', ',array_keys($escapedData)) . ') VALUES(' . join(', ', array_values($escapedData)) . ')';
		return $sql;
	}

	private function generateDeleteQuery($where) {
		$sql = 'DELETE FROM ' . $this->escapeTablename($this->tableName) . ' WHERE ' . $where;
		return $sql;
	}

	private function generateWhereQuery($mixed) {
		$escapedData = $this->escapeAssocDataToArray($mixed);
		return join(' AND ', $escapedData);
	}

	private function generateSelectQuery($where, $order, $group, $limit) {
		$sql = 'SELECT * FROM ' . $this->escapeTablename($this->tableName) . ' WHERE ' . $where;

		if ($order)
			$sql .= ' ORDER BY ' . $order;

		if ($group)
			$sql .= ' GROUP BY ' . $group;

		if ($limit)
			$sql .= ' LIMIT ' . $limit;

		return $sql;
	}

	public function __toString() {
		// Support for checking if an object is empty
		if ($this->isNew) {
			$isEmpty = true;
			foreach ($this->fields as $fieldName => $fieldInfo) {
				if (!empty($this->$fieldName)) {
					$isEmpty = false;
					break;
				}
			}
			if ($isEmpty)
				return '';
		}

		$buffer = get_class($this) . "(" . $this->{$this->primaryKey} . ")\n";
		foreach ($this->fields as $fieldName => $fieldInfo) {
			$buffer .= "\t" . ucfirst($fieldName) . ': ' . $this->$fieldName . "\n";
		}
		foreach ($this->hasMany as $fieldName => $class) {
			$buffer .= "\t" . ucfirst($fieldName) . ": (reference to $class objects)\n";
		}
		foreach ($this->belongsTo as $fieldName => $class) {
			$buffer .= "\t" . ucfirst($fieldName) . ": (reference to a $class object)\n";
		}
		return $buffer;
	}

	// Overwritable
	public function beforeSave() {
		return true;
	}

	public function afterLoad() {
		return true;
	}

	public function beforeDestroy() {
		return true;
	}

}

?>
