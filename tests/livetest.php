<?php
require_once('simpletest/autorun.php');
require_once('simpleactiverecord.php');

class User extends SimpleActiveRecord {
	public $has_many = array( 'blogposts' => 'Blogpost' );
	public $belongs_to = array( 'customer' => 'Customer' );
	public $serialize = 'meta';
}


class Customer extends SimpleActiveRecord {
	public $has_many = array( 'users' => 'User' );
}

class Blogpost extends SimpleActiveRecord {
	public $belongs_to = array( 'user' => 'User' );
}


class MinimalClassWithMysqlAdapter extends UnitTestCase {

	function setUp() {
	        mysql_pconnect('127.0.0.1','ormtest','password');
	        mysql_select_db('ormtest');

	        dbAdapterWrapper::setAdapter('mysqlAdapter');
	}

	function testInitialization() {
		$user = new User();
		$this->assertTrue(true);
	}

	function testInitWithId() {
	        $user = new User(1);
		$this->assertEqual($user->username, 'haakon');
	}

	function testInitWithKVP() {
	        $user = new User('id', 1);
		$this->assertEqual($user->username, 'haakon');
	}

	function testFindFirstBy() {
	        $user = new User();
		$newuser = $user->findFirstBy('id', 1);
		$this->assertEqual($newuser->username, 'haakon');
	}

	function testFindBy() {
	        $user = new User();
		$users = $user->findBy('id', 1);
		$this->assertEqual($users[0]->username, 'haakon');
	}

	function testFindByOrderSQL() {
	        $user = new User();
		$users = $user->findBy('id', 1, 'username ASC');
		$this->assertEqual($user->lastSqlQuery, 'SELECT * FROM `users` WHERE `id` = 1 ORDER BY username ASC');
	}

	function testHasManyRelation() {
		$user = new User(1);
		$numbers = $user->agentnumbers;
		$this->assertTrue(is_array($numbers));
	}

	function testBelongsToRelation() {
		$user = new User(1);
		$customer = $user->customer;
		$this->assertTrue(is_object($customer));
	}

}

?>
