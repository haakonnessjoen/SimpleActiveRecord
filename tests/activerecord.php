<?php
require_once('simpletest/autorun.php');
require_once('simpleactiverecord.php');

class testAdapter extends SimpleDbAdapter {
	var $sql = array();

	public function lastSQL($index=0) {
		if (count($this->sql) > 0)
			return $this->sql[count($this->sql)-1-$index];
		return null;
	}

	// mimic mysql
	public function getTableFields($recordObject) {
		$recordObject->i = 0;
		// Simulate mysql query
		$this->runQuery(&$recordObject, 'EXPLAIN ' . $this->escapeTablename($recordObject->getTableName()));
		return array(
			'id'    => array( 'type' => 'int', 'null' => true ),
			'name'  => array( 'type' => 'string', 'null' => false),
			'meta1' => array( 'type' => 'string', 'null' => false),
			'meta2' => array( 'type' => 'string', 'null' => false),
			'system_id' => array ('type' => 'int', 'null' => true)
		);
	}

	public function lastInsertId($res) {
		return 5;
	}

	// for easy testing
	public function runQuery($recordObject, $sql) {
		$this->sql[] = $sql;
		return 'handle';
	}

	public function fetchRow($recordObject, $resource) {
		$recordObject->i++;
		return $recordObject->i == 1 ? array('id' => '1', 'meta1' => 'a:2:{s:6:"active";i:1;s:11:"agent_login";i:1254826435;}', 'meta2' => 'a:2:{s:6:"active";i:1;s:11:"agent_login";i:1254826436;}') : null;
	}

}

class TestUser extends SimpleActiveRecord {
	protected $tableName = 'users';
	protected $primaryKey = 'id';
	protected $serialize = 'meta1,meta2';
	public $hasMany = array('posts','contacts');
	public $belongsTo = array('system' => 'TestSystem');
}

class TestSystem extends SimpleActiveRecord {
	protected $tableName = 'system';
	protected $primaryKey = 'id';
	public $hasMany = array('users' => 'TestUser');

	public function __construct() {
		$args = func_get_args();
		call_user_func('parent::__construct', $args);

		$this->fields = array(
			'id'    => array( 'type' => 'int', 'null' => true ),
			'name'  => array( 'type' => 'string', 'null' => false)
		);
	}
}

class TestOfARExpect extends UnitTestCase {

	function testSingleStringToArray() {
		$this->assertEqual(serialize(array('test')),serialize(ARExpect::expectArray('test')));
	}

	function testStringToArray() {
		$this->assertEqual(serialize(array('test','test2')),serialize(ARExpect::expectArray('test,test2')));
	}

}

class TestOfMinimalDBAdapter extends UnitTestCase {

	function setUp() {
		SimpleDbAdapterWrapper::setAdapter('testAdapter');
	}

	function testInitialization() {
		$user = new TestUser();
		$this->assertEqual(SimpleDbAdapterWrapper::$adapter->lastSQL(), 'EXPLAIN users');
	}

	function testCache() {
		$user = new TestUser();
		$this->assertEqual(SimpleDbAdapterWrapper::$adapter->lastSQL(), NULL);
	}

	function testFindFirst() {
		$user = new TestUser();
		$data = $user->findFirstBy('name', 'haakon');
		$this->assertEqual(SimpleDbAdapterWrapper::$adapter->lastSQL(), 'SELECT * FROM users WHERE name = \'haakon\' LIMIT 1');
	}

	function testFindFirstTypeHandling() {
		$user = new TestUser();
		$data = $user->findFirstBy('id', 1);
		$this->assertEqual(SimpleDbAdapterWrapper::$adapter->lastSQL(), 'SELECT * FROM users WHERE id = 1 LIMIT 1');
	}

	function testFindFirstTypeHandlingWithInvalidType() {
		$user = new TestUser();
		$data = $user->findFirstBy('id', 'not a id');
		$this->assertEqual(SimpleDbAdapterWrapper::$adapter->lastSQL(), 'SELECT * FROM users WHERE id = 0 LIMIT 1');
	}

	function testFindFirstNullHandlingTrue() {
		$user = new TestUser();
		$data = $user->findFirstBy('id', null); // id field supports NULL
		$this->assertEqual(SimpleDbAdapterWrapper::$adapter->lastSQL(), 'SELECT * FROM users WHERE id = NULL LIMIT 1');
	}

	function testFindFirstNullHandlingFalse() {
		$user = new TestUser();
		$data = $user->findFirstBy('name', null); // name field does not support NULL
		$this->assertEqual(SimpleDbAdapterWrapper::$adapter->lastSQL(), 'SELECT * FROM users WHERE name = \'\' LIMIT 1');
	}

	function testWhereEscapeFieldSQLExpression() {
		$user = new TestUser();
		$data = $user->findFirstBy('name', new SQLExpression('CONCAT(name)')); // test sql function testing
		$this->assertEqual(SimpleDbAdapterWrapper::$adapter->lastSQL(), 'SELECT * FROM users WHERE name = CONCAT(name) LIMIT 1');
	}

	function testEscapeFieldSQLExpression() {
		$user = new TestUser();
		$user->name = new SQLExpression('CONCAT("my name ", NOW())');
		$user->save();
		$this->assertEqual(SimpleDbAdapterWrapper::$adapter->lastSQL(1), 'INSERT INTO users (name, meta1, meta2, system_id, id) VALUES(CONCAT("my name ", NOW()), \'\', \'\', NULL, NULL)');
	}

	function testUnserialization() {
		$user = new TestUser(1);
		$this->assertEqual($user->meta1['agent_login'], 1254826435);
	}

	function testSerialization() {
		$user = new TestUser(1);
		$user->meta2['active'] = 0;
		$user->save();
		$this->assertEqual(SimpleDbAdapterWrapper::$adapter->lastSQL(), 'UPDATE users SET name = \'\', meta1 = \'a:2:{s:6:\"active\";i:1;s:11:\"agent_login\";i:1254826435;}\', meta2 = \'a:2:{s:6:\"active\";i:0;s:11:\"agent_login\";i:1254826436;}\', system_id = NULL WHERE id = 1 LIMIT 1');
		$user->meta2['active'] = 1;
		$user->save();
	}

	function testSerializationCleanup() {
		$user = new TestUser(1);
		$user->meta2['active'] = 0;
		$user->save();
		$this->assertEqual($user->meta2['active'], 0);
		$user->meta2['active'] = 1;
		$user->save();
	}

	function testFind() {
		$user = new TestUser();
		$data = $user->findBy('name', 'haakon');
		$this->assertEqual(SimpleDbAdapterWrapper::$adapter->lastSQL(), 'SELECT * FROM users WHERE name = \'haakon\'');
	}

	function testExpectHasManyType() {
		$user = new TestUser();
		$this->assertTrue(is_array($user->hasMany));
	}

	function testInsert() {
		$user = new TestUser();
		$user->name = 'myname';
		$user->save();
		$this->assertEqual(SimpleDbAdapterWrapper::$adapter->lastSQL(1), 'INSERT INTO users (name, meta1, meta2, system_id, id) VALUES(\'myname\', \'\', \'\', NULL, NULL)');
	}

	function testInsertId() {
		$user = new TestUser();
		$user->save();
		$this->assertEqual(SimpleDbAdapterWrapper::$adapter->lastSQL(), 'SELECT * FROM users WHERE id = 5 LIMIT 1');
	}

	function testDestroy() {
		$user = new TestUser(1);
		$user->destroy();
		$this->assertEqual(SimpleDbAdapterWrapper::$adapter->lastSQL(), 'DELETE FROM users WHERE id = 1');
	}

	function testDestroyBy() {
		$user = new TestUser();
		$user->destroyBy('username','haakon');
		$this->assertEqual(SimpleDbAdapterWrapper::$adapter->lastSQL(), 'DELETE FROM users WHERE id = 1');
	}

	function testUpdateIdWithObjectBelongsTo() {
		$system = new TestSystem();
		$system->id = 10;
		$system->name = 'test';

		$user = new TestUser();
		$user->name = 'testnavn';
		$user->system = $system;
		$user->save();
		$this->assertEqual(SimpleDbAdapterWrapper::$adapter->lastSQL(1), 'INSERT INTO users (name, meta1, meta2, system_id, id) VALUES(\'testnavn\', \'\', \'\', 10, NULL)');
	}

	function testUpdateIdWithObjectHasMany() {
		$system = new TestSystem();
		$system->id = 123;

		$user = new TestUser(1);

		// Assign array of user objects
		$system->users = array($user);

		$this->assertEqual(SimpleDbAdapterWrapper::$adapter->lastSQL(), 'UPDATE users SET name = \'\', meta1 = \'a:2:{s:6:\"active\";i:1;s:11:\"agent_login\";i:1254826435;}\', meta2 = \'a:2:{s:6:\"active\";i:1;s:11:\"agent_login\";i:1254826436;}\', system_id = 123 WHERE id = 1 LIMIT 1');
	}
}

?>
