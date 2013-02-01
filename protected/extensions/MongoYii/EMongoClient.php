<?php

/**
 * Quite deceptively this actually represents the DATABASE not the connection.
 *
 * Normally this would represent the MongoClient or Mongo and it is even named after them and implements
 * some of their functions but it is not due to the way Yii works.
 */
class EMongoClient extends CApplicationComponent{

	/**
	 * The server string (connection string pre-1.3)
	 * @var string
	 */
	public $server;

	/**
	 * Additional options for the connection constructor
	 * @var array
	 */
	public $options = array();

	/**
	 * The name of the database
	 * @var string
	 */
	public $db;

	/**
	 * Write Concern, will default to acked writes
	 * @see http://php.net/manual/en/mongo.writeconcerns.php
	 * @var int|string
	 */
	public $w = 1;

	/**
	 * Are we using journaled writes here? Beware this makes all writes wait for the journal, it does not
	 * state whether MongoDB is using journaling. Only works 1.3+ driver
	 * @var boolean
	 */
	public $j = false;

	/**
	 * The read preference
	 * The first param is the textual version of the constant name in the MongoClient for the type of read
	 * i.e. RP_PRIMARY and the second emulates the setReadPreference prototypes second parameter
	 * @see http://php.net/manual/en/mongo.readpreferences.php
	 * @var array()
	 */
	public $RP = array('RP_PRIMARY', array());

	/**
	 * The Legacy read preference. DO NOT USE IF YOU ARE ON VERSION 1.3+
	 * @var boolean
	 */
	public $setSlaveOk = false;

	/**
	 * The Mongo Connection instance
	 * @var Mongo|MongoClient
	 */
	private $_mongo;

	/**
	 * The database instance
	 * @var MongoDB
	 */
	private $_db;

	/**
	 * Caches reflection properties for our objects so we don't have
	 * to keep getting them
	 * @var array
	 */
	private $_objCache = array();

	/**
	 * Will either call a function on the database or call for a collection
	 */
	function __call($name,$parameters = array()){
		if(method_exists($this->_db, $name)){
			return call_user_func_array(array($this->_db, $name), $parameters);
		}
		return $this->selectCollection($name);
	}

	/**
	 * The init function
	 * We also connect here
	 * @see yii/framework/base/CApplicationComponent::init()
	 */
	function init(){
		parent::init();
		$this->connect();
	}

	/**
	 * Connects to our database
	 */
	function connect(){

		// We don't need to throw useless exceptions here, the MongoDB PHP Driver has its own checks and error reporting
		// Yii will easily and effortlessly display the errors from the PHP driver, we should only catch its exceptions if
		// we wanna add our own custom messages on top which we don't, the errors are quite self explanatory
		if(version_compare(phpversion('mongo'), '1.3.0', '<')){
			$this->_mongo = new Mongo($this->server, $this->options);
			$this->_mongo->connect();

			if($this->setSlaveOk)
				$this->_mongo->setSlaveOkay($this->setSlaveOk);
		}else{
			$this->_mongo = new MongoClient($this->server, $this->options);

			if(is_array($this->RP)){
				$const = $this->RP[0];
				$opts = $this->RP[1];

				$this->_mongo->setReadPreference(MongoClient::$const, $opts);
			}
		}
	}

	/**
	 * Gets the connection object
	 * @return Mongo|MongoClient
	 */
	function getConnection(){

		if(empty($this->_mongo))
			$this->connect();

		return $this->_mongo;
	}

	/**
	 * Sets the raw database adhoc
	 * @param $name
	 */
	function setDB($name){
		$this->_db = $this->getConnection()->selectDb($name);
	}

	/**
	 * Gets the raw Database
	 * @return MongoDB
	 */
	function getDB(){

		if(empty($this->_db))
			$this->setDB($this->db);

		return $this->_db;
	}

	/**
	 * You should never call this function.
	 *
	 * The PHP driver will handle connections automatically, and will
	 * keep this performant for you.
	 */
	function close(){
		if(!empty($this->_mongo))
			$this->_mongo->close();
	}

	/**
	 * Since there is no easy definition of the public collection class without drilling down
	 * this function is designed to be a helper to make aggregation calling more standard.
	 * @param $collection
	 * @param $pipelines
	 */
	function aggregate($collection, $pipelines){
		if(version_compare(phpversion('mongo'), '1.3.0', '<')){
			return $this->_db->command(array(
				'aggregate' => $collection,
				'pipeline' => $pipelines
			));
		}
		return $this->_db->$collection->aggregate($pipelines);
	}

	/**
	 * ATM does nothing but the original processing; ATM
	 * @param $name
	 */
	function selectCollection($name){
		return $this->_db->selectCollection($name);
	}

	/**
	 * Provides a method by which to set some sort of cache for a Document to
	 * remember things such as reflection of fields
	 * @param string $name
	 * @param array $virtualFields
	 * @param array $documentFields
	 */
	function setObjectCache($name, $virtualFields = null, $documentFields = null){

		if($virtualFields)
			$this->_objCache[$name]['virtual'] = $virtualFields;

		if($documentFields)
			$this->_objCache[$name]['document'] = $documentFields;
	}

	/**
	 * Gets the virtual fields of a Document from cache
	 * @param string $name
	 * @return NULL|array
	 */
	function getVirtualObjCache($name){
		return isset($this->_objCache[$name], $this->_objCache[$name]['virtual']) ? $this->_objCache[$name]['virtual'] : null;
	}

	/**
	 * Gets the field of a Document from cache
	 * @param string $name
	 * @return NULL|array
	 */
	function getFieldObjCache($name){
		return isset($this->_objCache[$name], $this->_objCache[$name]['document']) ? $this->_objCache[$name]['document'] : null;
	}

	/**
	 * Just gets the object cache for a Document
	 * @param string $name
	 * @return NULL|array
	 */
	function getObjCache($name){
		return isset($this->_objCache[$name]) ? $this->_objCache[$name] : null;
	}

	/**
	 * Gets the default write concern options for all queries through active record
	 * @return array
	 */
	function getDefaultWriteConcern(){
		if(version_compare(phpversion('mongo'), '1.3.0', '<')){
			if($this->w == 1){
				return array('safe' => true);
			}elseif($this->w > 0){
				return array('safe' => $this->w);
			}
		}else{
			return array('w' => $this->w, 'j' => $this->j);
		}
		return array();
	}
}