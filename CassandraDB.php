<?php

namespace Yee\Libraries\Database;

use Cassandra;

class CassandraDB
{

    /**
     * Static instance of self
     *
     * @var CassandraDB
     */
    protected static $_instance;
    protected $_cluster;
    protected $_session;
    protected $_keyspace;
    protected $isConnected = false;

    /**
     * The CQL query to be prepared and executed
     *
     * @var string
     */
    protected $_query;

    /**
     * An array that holds where conditions 'fieldname' => 'value'
     *
     * @var array
     */
    protected $_where = array();

    /**
     * An Array that holds all parameters which will be binded.
     *
     * @var array
     */
    protected $_bindParams = array();

    /**
     * Boolean - Toggle which defines if the select results will be converted
     * from Cassandra object to appropriate data type.
     *
     * Default: true
     *
     * @var boolean
     */
    public $autoConvert = true;

    /**
     * An Array that holds table information as 'column name' => 'column type'
     *
     * @var array 
     */
    protected $_table = array();

    /**
     * String that holds the name of the table which is used
     *
     * @var string Name of the table
     */
    protected $_tableName;

    /**
     * Database credentials
     *
     * @var string
     */
    protected $seeds;
    protected $username;
    protected $password;
    protected $port;
    protected $keyspace;

    /**
     * Constructor assigning the new credentials.
     *
     * @param string $seed_nodes
     * @param integer $port
     * @param string $cas_username
     * @param string $cas_pass
     * @param string $keyspace
     */
    public function __construct( $seed_nodes, $cas_username, $cas_pass, $port, $keyspace )
    {
        $this->seeds = $seed_nodes;
        $this->username = $cas_username;
        $this->password = $cas_pass;
        $this->port = $port;
        $this->keyspace = $keyspace;

        self::$_instance = $this;
    }

    /**
     * A method to connect to the DatabaseManager
     */
    public function connect()
    {

        $this->_cluster = Cassandra::cluster()
                ->withContactPoints( implode( ',', $this->seeds ) );

        if ( $this->username != '' && $this->password != '' ) {
            $this->_cluster = $this->_cluster->withCredentials( $this->username, $this->password );
        }

        if ( $this->port != '' ) {
            $this->_cluster = $this->_cluster->withPort( $this->port );
        }

        $this->_cluster = $this->_cluster->build();

        $this->_session = $this->_cluster->connect( $this->keyspace );

        $this->_keyspace = $this->_session->schema()->keyspace( $this->keyspace );

        $this->isConnected = true;
    }

    /**
     * A method of returning the static instance to allow access to the
     * instantiated object from within another class.
     * Inheriting this class would require reloading connection info.
     *
     * @return object Returns the current instance.
     */
    public function getInstance()
    {
        return self::$_instance;
    }

    /**
     * Reset states after an execution
     *
     * @return object Returns the current instance.
     */
    protected function reset()
    {
        $this->_where = array();
        $this->_groupBy = array();
        $this->_query = null;
        $this->_bindParams = array();
        // $this->count = 0; ------------------------------ To be deleted
    }

    /**
     * Pass in a raw query and execute it.
     *
     * @param string $query      Contains a user-provided query.
     *
     * @return array | boolean   Contains the returned rows from the query OR the state of the executed query.
     */
    public function rawQuery( $query )
    {
        if ( !$this->isConnected ) {
            $this->connect();
        }

        try {

            $stmt = new Cassandra\SimpleStatement( $query );
            $result = $this->_session->execute( $stmt );
        } catch ( Cassandra\Exception $e ) {

            echo $e->getMessage();
            return false;
        }

        if ( $result->count() > 0 ) {
            return $this->_extractRows( $result );
        }

        return true;
    }

    /**
     * A convenient SELECT * function.
     *
     * @param string  $tableName The name of the database table to work with.
     * @param integer $numRows   The number of rows total to return.
     *
     * @return array Contains the returned rows from the select query.
     */
    public function get( $tableName, $numRows = null, $columns = '*' )
    {
        if ( !$this->isConnected ) {
            $this->connect();
        }

        if ( empty( $columns ) ) {
            $columns = '*';
        }

        $column = is_array( $columns ) ? implode( ', ', $columns ) : $columns;
        $this->_query = "SELECT $column FROM " . $tableName;

        $stmt = $this->_buildQuery( $numRows );

        if ( $stmt == false ) {
            return false;
        }

        if ( $hasWhere = !empty( $this->_bindParams ) ) {
            $bind = $this->_buildBindParams( $this->_bindParams );
        }

        try {

            if ( $hasWhere ) {

                $result = $this->_session->execute( $stmt, $bind );
            } else {

                $result = $this->_session->execute( $stmt );
            }
        } catch ( Exception $e ) {

            echo $e->getMessage();
            return false;
        }

        $this->reset();

        return $this->_extractRows( $result );
    }

    /**
     * A convenient SELECT * function to get one record.
     *
     * @param string  $tableName The name of the database table to work with.
     *
     * @return array Contains the returned rows from the select query.
     */
    public function getOne( $tableName, $columns = '*' )
    {
        if ( !$this->isConnected ) {
            $this->connect();
        }

        $res = $this->get( $tableName, 1, $columns );

        if ( is_object( $res ) ) {
            return $res;
        }

        if ( isset( $res[0] ) ) {
            return $res[0];
        }

        return null;
    }

    /**
     * Insert query. Inserts new data into the table.
     * (If the new data already exists it will update it)
     *
     * @param <string $tableName The name of the table.
     * @param array $insertData Data containing information for inserting into the DB.
     *
     * @return boolean Boolean indicating whether the insert query was completed succesfully.
     */
    public function insert( $tableName, $insertData )
    {
        if ( !$this->isConnected ) {
            $this->connect();
        }

        if ( empty( $this->_tableName ) || $this->_tableName != $tableName ) {
            $this->_tableName = $tableName;
            $this->_initTable( $this->_keyspace->table( $tableName ) );
        }

        $this->_query = "INSERT INTO " . $tableName;

        $stmt = $this->_buildQuery( null, $insertData );

        if ( $stmt == false ) {
            return false;
        }

        $bind = $this->_buildBindParams( $insertData );

        try {

            $result = $this->_session->execute( $stmt, $bind );
        } catch ( Cassandra\Exception $e ) {

            echo $e->getMessage();
            return false;
        }

        $this->reset();

        return true;
    }

    /**
     * Update query. Be sure to first call the "where" method.
     * (If the primary key doesn't exist it will work like insert)
     *
     * @param string $tableName The name of the database table to work with.
     * @param array  $tableData Array of data to update the desired row.
     *
     * @return boolean
     */
    public function update( $tableName, $tableData )
    {
        if ( !$this->isConnected ) {
            $this->connect();
        }

        if ( empty( $this->_tableName ) || $this->_tableName != $tableName ) {
            $this->_tableName = $tableName;
            $this->_initTable( $this->_keyspace->table( $tableName ) );
        }

        $this->_query = "UPDATE " . $tableName . " SET ";

        $stmt = $this->_buildQuery( null, $tableData );

        if ( $stmt == false ) {
            return false;
        }

        foreach ( $tableData as $key => $value ) {
            $this->_bindParams[$key] = $value;
        }

        $bind = $this->_buildBindParams( $this->_bindParams );

        try {

            $result = $this->_session->execute( $stmt, $bind );
        } catch ( Cassandra\Exception $e ) {

            echo $e->getMessage();
            return false;
        }

        $this->reset();

        return true;
    }

    /**
     * Delete query. Call the "where" method first.
     *
     * @param string  $tableName The name of the database table to work with.
     * @param integer $numRows   The number of rows to delete.
     *
     * @return boolean 
     */
    public function delete( $tableName, $numRows = null )
    {
        if ( !$this->isConnected ) {
            $this->connect();
        }

        if ( empty( $this->_tableName ) || $this->_tableName != $tableName ) {
            $this->_tableName = $tableName;
            $this->_initTable( $this->_keyspace->table( $tableName ) );
        }

        $this->_query = "DELETE FROM " . $tableName;

        $stmt = $this->_buildQuery( $numRows );

        if ( $stmt == false ) {
            return false;
        }

        $bind = $this->_buildBindParams( $this->_bindParams );

        try {

            $this->_session->execute( $stmt, $bind );
        } catch ( Cassandra\Exception $e ) {
            echo $e->getMessage();
            return false;
        }

        $this->reset();

        return true;
    }

    /**
     * This method allows you to specify multiple (method chaining optional) AND WHERE statements for CQL queries.
     *
     * @param string $whereProp  The name of the database field.
     * @param mixed  $whereValue The value of the database field.
     *
     * @return CassandraDb
     */
    public function where( $whereProp, $whereValue = null, $operator = null )
    {
        if ( !$this->isConnected ) {
            $this->connect();
        }

        if ( $operator ) {
            $whereValue = Array( $operator => $whereValue );
        }

        $this->_where[] = Array( "AND", $whereValue, $whereProp );
        $this->_bindParams[$whereProp] = $whereValue;
        return $this;
    }

    /**
     * Abstraction method that will compile the WHERE statement,
     * any passed update data, and the desired rows.
     * It then builds the CQL query.
     *
     * @param int   $numRows   The number of rows total to return.
     * @param array $tableData Should contain an array of data for updating the database.
     *
     * @return cassandra_stmt Returns the $stmt object.
     */
    protected function _buildQuery( $numRows = null, $tableData = null )
    {
        if ( !$this->isConnected ) {
            $this->connect();
        }

        $this->_buildTableData( $tableData );
        $this->_buildWhere();
        $this->_buildLimit( $numRows );

        // Prepare query
        $stmt = $this->_prepareQuery();

        return $stmt;
    }

    /**
     * Abstraction method that will build an INSERT or UPDATE part of the query
     */
    protected function _buildTableData( $tableData )
    {
        if ( !$this->isConnected ) {
            $this->connect();
        }

        if ( !is_array( $tableData ) ) {
            return;
        }

        $isInsert = strpos( $this->_query, 'INSERT' );
        $isUpdate = strpos( $this->_query, 'UPDATE' );

        if ( $isInsert !== false ) {
            $this->_query .= ' (' . implode( array_keys( $tableData ), ', ' ) . ')';
            $this->_query .= ' VALUES (';
        }

        foreach ( $tableData as $column => $value ) {
            if ( $isUpdate !== false ) {
                $this->_query .= " " . $column . " = ";
            }

            // Simple value - extract parameters to be binded
            if ( !is_array( $value ) ) {
                $this->_query .= '?, ';
                continue;
            }

            // Function value
            $key = key( $value );
            $val = $value[$key];
            switch ( $key ) {
                case '[I]':
                    $this->_query .= $column . $val . ", ";
                    break;
                case '[F]':
                    $this->_query .= $val[0] . ", ";
                    if ( !empty( $val[1] ) )
                        break;
                case '[N]':
                    if ( $val == null )
                        $this->_query .= "!" . $column . ", ";
                    else
                        $this->_query .= "!" . $val . ", ";
                    break;
                default:
                    die( "Wrong operation" );
            }
        }
        $this->_query = rtrim( $this->_query, ', ' );

        if ( $isInsert !== false ) {
            $this->_query .= ')';
        }
    }

    /**
     * Abstraction method that will build the part of the WHERE conditions
     */
    protected function _buildWhere()
    {
        if ( !$this->isConnected ) {
            $this->connect();
        }

        if ( empty( $this->_where ) ) {
            return;
        }

        //Prepair the where portion of the query
        $this->_query .= ' WHERE ';

        // Remove first AND/OR concatenator
        $this->_where[0][0] = '';
        foreach ( $this->_where as $cond ) {
            list ( $concat, $wValue, $wKey ) = $cond;

            $this->_query .= " " . $concat . " " . $wKey;

            // Empty value (raw where condition in wKey)
            if ( $wValue === null ) {
                continue;
            }

            // Simple = comparison
            if ( !is_array( $wValue ) ) {
                $wValue = Array( '=' => $wValue );
            }

            $key = key( $wValue );
            $val = $wValue[$key];

            switch ( strtolower( $key ) ) {
                case '0':
                    break;
                case 'not in':
                case 'in':
                    $comparison = ' ' . $key . ' (';
                    foreach ( $val as $v ) {
                        $comparison .= ' ?,';
                    }
                    $this->_query .= rtrim( $comparison, ',' ) . ' ) ';
                    break;
                case 'not between':
                case 'between':
                    $this->_query .= " $key ? AND ? ";
                    break;
                default:
                    $this->_query .= $this->_buildPair( $key, $val );
            }
        }
    }

    /**
     * Helper function to add variables into bind parameters array and will return
     * its CQL part of the query according to operator in ' $operator ?'
     *
     * @param Array Variable with values
     */
    protected function _buildPair( $operator, $value )
    {
        if ( !$this->isConnected ) {
            $this->connect();
        }

        if ( !is_object( $value ) ) {
            return ' ' . $operator . ' ? ';
        }

        return " " . $operator . " (" . $subQuery['query'] . ")";
    }

    /**
     * Abstraction method that will build the LIMIT part of the WHERE statement
     *
     * @param int   $numRows   The number of rows total to return.
     */
    protected function _buildLimit( $numRows )
    {
        if ( !$this->isConnected ) {
            $this->connect();
        }

        if ( !isset( $numRows ) ) {
            return;
        }

        if ( is_array( $numRows ) ) {
            $this->_query .= ' LIMIT ' . (int) $numRows[0] . ', ' . (int) $numRows[1];
        } else {
            $this->_query .= ' LIMIT ' . (int) $numRows;
        }
    }

    /**
     * Method attempts to prepare the CQL query
     *
     * @return cassandra_stmt
     */
    protected function _prepareQuery()
    {
        if ( !$this->isConnected ) {
            $this->connect();
        }

        try {

            $stmt = $this->_session->prepare( $this->_query );
            return $stmt;
        } catch ( Cassandra\Exception $e ) {

            echo '<strong>ERROR - PREPARE STATEMENT</strong>: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Creates a Cassandra bind on parameters
     *
     * @param array
     *
     * @return object 
     */
    protected function _buildBindParams( $items )
    {
        if ( !$this->isConnected ) {
            $this->connect();
        }

        $items = $this->_convertParams( $items );

        try {

            return new Cassandra\ExecutionOptions( array( 'arguments' => $items ) );
        } catch ( Cassandra\Exception $e ) {

            echo '<strong>ERROR - BIND</strong>: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Extracts all rows from the executed Cassandra statement
     *
     * @param object Cassandra executed statement
     *
     * @return array All rows from the executed statement
     */
    protected function _extractRows( $result )
    {
        if ( !$this->isConnected ) {
            $this->connect();
        }

        $arrayResults = array();
        $keys = array_keys( $result[0] );

        for ( $i = 0; $i < $result->count(); $i++ ) {

            array_push( $arrayResults, $result[$i] );

            if ( $this->autoConvert ) {

                foreach ( $keys as $key ) {

                    if ( !is_string( $arrayResults[$i][$key] ) && !is_int( $arrayResults[$i][$key] ) && !is_double( $arrayResults[$i][$key] ) && !is_null( $arrayResults[$i][$key] ) ) {
                        $arrayResults[$i][$key] = $this->_convertFromCassandraObject( $arrayResults[$i][$key] );
                    }
                }
            }
        }

        return $arrayResults;
    }

    /**
     * Converts from cassandra object to appropriate data format
     *
     * @param Cassandra Object
     *
     * @return Appropriate data format
     */
    protected function _convertFromCassandraObject( $col )
    {
        if ( !$this->isConnected ) {
            $this->connect();
        }

        if ( $col->type() == 'timestamp' ) {
            return $col->toDateTime();
        }

        if ( $col->type() == 'bigint' ) {
            return $col->toInt();
        }

        if ( $col->type() == 'decimal' || $col->type() == 'float' ) {
            return $col->toDouble();
        }

        if ( $col->type() == 'varint' ) {
            return $col->value();
        }

        if ( $col->type() == 'blob' ) {
            return $col->bytes();
        }

        if ( $col->type() == 'uuid' || $col->type() == 'timeuuid' ) {
            return $col->uuid();
        }

        if ( $col->type() == 'inet' ) {
            return $col->address();
        }
    }

    /**
     * Initializes the table information
     * 'column name' => 'column type'
     *
     * @param object Cassandra\DefaultTable
     *
     */
    protected function _initTable( $table )
    {
        $this->_table = array();

        foreach ( $table->columns() as $column ) {
            $this->_table[$column->name()] = $column->type();
        }
    }

    /**
     * Loops through all parameters which will be converted
     * to appropriate Cassandra object type
     *
     * @param array     Parameters prior conversion
     *
     * @return array    Parameters after conversion
     */
    protected function _convertParams( $params )
    {
        foreach ( array_keys( $params ) as $paramKey ) {
            $params[$paramKey] = $this->_convertToCassandraObject( $params[$paramKey], $paramKey );
        }

        return $params;
    }

    /**
     * Converts the parameter value of the column
     * to the appropriate Cassandra object type
     *
     * @param object Value of the parameter
     * @param string Column name of the parameter
     *
     * @return object New cassandra object OR the old value
     */
    protected function _convertToCassandraObject( $paramValue, $paramKey )
    {
        if ( $this->_table[$paramKey] == 'int' || $this->_table[$paramKey] == 'varchar' || $this->_table[$paramKey] == 'double' ) {
            return $paramValue;
        }

        if ( $this->_table[$paramKey] == 'timestamp' ) {
            return new Cassandra\Timestamp( strtotime( $paramValue ) );
        }

        if ( $this->_table[$paramKey] == 'bigint' ) {
            return new Cassandra\Bigint( $paramValue );
        }

        if ( $this->_table[$paramKey] == 'decimal' ) {
            return new Cassandra\Decimal( strval( $paramValue ) );
        }

        if ( $this->_table[$paramKey] == 'float' ) {
            return new Cassandra\Float( strval( $paramValue ) );
        }

        if ( $this->_table[$paramKey] == 'varint' ) {
            return new Cassandra\Varint( $paramValue );
        }

        if ( $this->_table[$paramKey] == 'blob' ) {
            return new Cassandra\Blob( $paramValue );
        }

        if ( $this->_table[$paramKey] == 'uuid' ) {
            return new Cassandra\Uuid();
        }

        if ( $this->_table[$paramKey] == 'timeuuid' ) {
            return new Cassandra\Timeuuid();
        }

        if ( $this->_table[$paramKey] == 'inet' ) {
            return new Cassandra\Inet( $paramValue );
        }
    }
}
